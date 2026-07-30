<?php

namespace App\Jobs;

use App\Enums\JobErrorCode;
use App\Events\PdfJobUpdated;
use App\Models\PdfJob;
use App\Notifications\PdfJob\PdfJobCompletedNotification;
use App\Services\PdfLineNumberService;
use App\Services\Quality\PageQualityEvaluator;
use App\Services\Quality\ProcessingReportGenerator;
use App\Settings\RetentionSettings;
use App\Support\PdfJobPayloadFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ProcessPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<string, mixed> */
    protected array $currentLiveState = [];

    protected int $currentProgress = 0;

    protected int $currentProcessedPages = 0;

    protected int $currentTotalPages = 0;

    protected ?int $currentEtaSeconds = null;

    public int $tries = 2;

    // Large scanned documents OCR at roughly 5-6 seconds per page on the
    // production host, so a 500+ page record of appeal legitimately needs a
    // few hours. Keep this below the queue connection's retry_after value
    // (REDIS_QUEUE_RETRY_AFTER) or the job will be handed to a second worker
    // and processed twice in parallel.
    public int $timeout = 14400;

    // Retrying after a timeout would re-run the whole multi-hour pipeline
    // from scratch; surface the failure to the user instead.
    public bool $failOnTimeout = true;

    protected float $lastPageProgressPublishedAt = 0.0;

    public function __construct(
        public string $pdfJobId
    ) {}

    public function handle(
        PdfLineNumberService $pdfService,
        PageQualityEvaluator $qualityEvaluator,
        ProcessingReportGenerator $reportGenerator,
        RetentionSettings $retentionSettings,
    ): void {
        // Large scanned documents accumulate hundreds of pages of OCR line data
        // in memory; the default 512M worker limit is not enough.
        ini_set('memory_limit', '1024M');

        Log::info('[TenthLine] ProcessPdfJob handle() entered', ['job_id' => $this->pdfJobId]);

        $inputPath = Storage::disk('local')->path("pdf-jobs/{$this->pdfJobId}/input.pdf");
        $outputPath = Storage::disk('local')->path("pdf-jobs/{$this->pdfJobId}/output.pdf");

        Log::info('[TenthLine] ProcessPdfJob: Job started', [
            'job_id' => $this->pdfJobId,
            'input_path' => $inputPath,
            'output_path' => $outputPath,
        ]);

        if (! file_exists($inputPath)) {
            Log::error('[TenthLine] ProcessPdfJob: Input file not found', ['input_path' => $inputPath]);
            $this->failJob('Input file not found.');
            return;
        }

        $job = PdfJob::find($this->pdfJobId);
        if (! $job) {
            Log::warning('[TenthLine] ProcessPdfJob: PdfJob record not found', ['job_id' => $this->pdfJobId]);
            return;
        }

        $this->currentTotalPages = max(0, (int) ($job->page_count ?? $job->total_pages ?? 0));
        $this->currentProcessedPages = 0;
        $this->currentProgress = 3;
        $this->currentEtaSeconds = null;

        $this->publishProcessingState(
            'analyzing_document',
            'Analyzing document',
            'We are reading the PDF and preparing the line-numbering layout.',
            3,
            [
                'detail' => $this->currentTotalPages > 0 ? "{$this->currentTotalPages} pages detected" : null,
                'total_pages' => $this->currentTotalPages > 0 ? $this->currentTotalPages : null,
                'processed_pages' => 0,
                'eta_seconds' => null,
            ]
        );

        Log::info('[TenthLine] ProcessPdfJob: Processing with options', [
            'job_id' => $this->pdfJobId,
            'line_interval' => $job->line_interval ?? 10,
            'margin' => $job->margin ?? 'right',
            'font_size_pt' => $job->font_size_pt ?? 8,
            'paddleocr_enabled' => (bool) config('paddleocr.enabled', false),
        ]);

        $startTime = microtime(true);
        $pageProgressFloor = 12;

        $processingStateCallback = function (array $state) use (&$pageProgressFloor): void {
            $progress = isset($state['progress']) && is_numeric($state['progress'])
                ? max(0, min(96, (int) round((float) $state['progress'])))
                : null;

            if ($progress !== null) {
                $pageProgressFloor = max($pageProgressFloor, $progress);
            }

            $this->publishProcessingState(
                (string) ($state['phase'] ?? 'processing'),
                (string) ($state['label'] ?? 'Processing document'),
                (string) ($state['message'] ?? 'We are still working on your document.'),
                $progress,
                $state
            );
        };

        try {
            $totalPages = $pdfService->addLineNumbers(
                $inputPath,
                $outputPath,
                (int) ($job->line_interval ?? 10),
                $job->margin ?? 'right',
                $job->font_size_pt ?? 8,
                function (int $pageNo, int $total) use ($startTime, &$pageProgressFloor) {
                    $progress = $this->mapPageProgress($pageNo, $total, $pageProgressFloor);
                    $elapsed = (int) (microtime(true) - $startTime);
                    $eta = $pageNo > 0 && $elapsed > 0
                        ? (int) (($elapsed / $pageNo) * ($total - $pageNo))
                        : 0;

                    $this->currentTotalPages = $total;
                    $this->currentProcessedPages = $pageNo;
                    $this->currentProgress = $progress;
                    $this->currentEtaSeconds = $eta;
                    $this->currentLiveState = [
                        'phase' => 'adding_line_numbers',
                        'label' => 'Adding line numbers',
                        'message' => 'We are placing line numbers across the document.',
                        'detail' => "Page {$pageNo} of {$total}",
                    ];

                    // Persisting/broadcasting every page floods the database,
                    // the log, and the websocket on large documents; a 2-second
                    // cadence is indistinguishable in the UI.
                    $now = microtime(true);
                    if ($pageNo !== 1 && $pageNo !== $total && ($now - $this->lastPageProgressPublishedAt) < 2.0) {
                        return;
                    }
                    $this->lastPageProgressPublishedAt = $now;

                    $update = [
                        'total_pages' => $total,
                        'processed_pages' => $pageNo,
                        'progress' => $progress,
                        'eta_seconds' => $eta,
                        'updated_at' => now(),
                    ];

                    if ($this->supportsOcrTrackingColumns()) {
                        $update['ocr_diagnostics'] = ['live' => $this->liveStatePayload()];
                    }

                    DB::table('pdf_jobs')->where('id', $this->pdfJobId)->update($update);

                    Log::info('[TenthLine] ProcessPdfJob: Progress updated', [
                        'job_id' => $this->pdfJobId,
                        'processed_pages' => $pageNo,
                        'total_pages' => $total,
                        'progress' => $progress,
                        'eta_seconds' => $eta,
                    ]);

                    $this->broadcastProgress($progress, $pageNo, $total, $eta);
                },
                [
                    'pdf_job_id' => $this->pdfJobId,
                    'processing_state_callback' => $processingStateCallback,
                ]
            );

            $runDiagnostics = $pdfService->getLastRunDiagnostics();
            $ocrAttributes = $this->buildOcrPersistenceAttributes($runDiagnostics);
            $relativeOutput = "pdf-jobs/{$this->pdfJobId}/output.pdf";

            // ── Quality evaluation & processing report ──────────────────────
            $this->publishProcessingState(
                'evaluating_quality',
                'Evaluating quality',
                'We are assessing processing quality for each page.',
                97,
            );

            $runPages = is_array($runDiagnostics['pages'] ?? null) ? $runDiagnostics['pages'] : [];
            $pageResults = $qualityEvaluator->evaluateAll($runPages);

            $job->refresh();
            $report = $reportGenerator->generate($job, $pageResults);

            // ── Zero payable pages: fail the job, discard output ────────────
            if ($report->hasZeroPayablePages()) {
                Log::warning('[TenthLine] ProcessPdfJob: zero payable pages after quality evaluation', [
                    'job_id' => $this->pdfJobId,
                    'uploaded_pages' => $report->uploaded_pages,
                    'successful_pages' => $report->successful_pages,
                    'low_confidence_pages' => $report->low_confidence_pages,
                    'failed_pages' => $report->failed_pages,
                ]);

                @unlink($outputPath);

                $failUpdate = [
                    'status' => 'failed',
                    'error_code' => JobErrorCode::ZeroSuccessfulPages->value,
                    'error_message' => JobErrorCode::ZeroSuccessfulPages->userMessage(),
                    'processing_report_id' => $report->id,
                    'total_pages' => $totalPages,
                    'updated_at' => now(),
                ];
                if ($ocrAttributes !== []) {
                    $failUpdate = array_merge($failUpdate, $ocrAttributes);
                }
                DB::table('pdf_jobs')->where('id', $this->pdfJobId)->update($failUpdate);
                $this->broadcastJobSnapshot();
                return;
            }

            // ── Transition to awaiting_payment ──────────────────────────────
            $paymentDeadlineAt = now()->addHours(
                max(1, $retentionSettings->payment_deadline_hours)
            );

            $awaitingUpdate = [
                'status' => 'awaiting_payment',
                'total_pages' => $totalPages,
                'processed_pages' => $totalPages,
                'progress' => 100,
                'eta_seconds' => 0,
                'output_path' => $relativeOutput,
                'processing_report_id' => $report->id,
                'payable_pages' => $report->payable_pages,
                'payment_deadline_at' => $paymentDeadlineAt,
                'updated_at' => now(),
            ];

            if ($ocrAttributes !== []) {
                $awaitingUpdate = array_merge($awaitingUpdate, $ocrAttributes);
            }

            DB::table('pdf_jobs')->where('id', $this->pdfJobId)->update($awaitingUpdate);

            $duration = round(microtime(true) - $startTime, 2);
            Log::info('[TenthLine] ProcessPdfJob: Job awaiting payment', [
                'job_id' => $this->pdfJobId,
                'total_pages' => $totalPages,
                'payable_pages' => $report->payable_pages,
                'total_amount' => $report->total_amount,
                'payment_deadline_at' => $paymentDeadlineAt->toIso8601String(),
                'duration_seconds' => $duration,
                'output_path' => $relativeOutput,
            ]);

            $this->broadcastJobSnapshot();
        } catch (\Throwable $e) {
            Log::error('[TenthLine] ProcessPdfJob: Job failed with exception', [
                'job_id' => $this->pdfJobId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->failJob($e->getMessage());
        }
    }

    protected function failJob(string $message, ?JobErrorCode $errorCode = null): void
    {
        Log::warning('[TenthLine] ProcessPdfJob: Marking job as failed', [
            'job_id' => $this->pdfJobId,
            'error_message' => $message,
            'error_code' => $errorCode?->value,
        ]);

        $update = [
            'status' => 'failed',
            'error_message' => $message,
            'error_code' => $errorCode?->value,
            'updated_at' => now(),
        ];

        if ($this->supportsOcrTrackingColumns()) {
            $update['ocr_status'] = 'failed';
            $update['ocr_error_message'] = $message;
        }

        DB::table('pdf_jobs')->where('id', $this->pdfJobId)->update($update);
        $this->broadcastJobSnapshot();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[TenthLine] ProcessPdfJob: Job failed (queue failed callback)', [
            'job_id' => $this->pdfJobId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);
        $this->failJob($exception->getMessage());
    }

    protected function broadcastProgress(int $progress, int $processedPages, int $totalPages, int $etaSeconds): void
    {
        event(new PdfJobUpdated(PdfJobPayloadFactory::withProcessingState([
            'id' => $this->pdfJobId,
            'status' => 'processing',
            'progress' => $progress,
            'processed_pages' => $processedPages,
            'total_pages' => $totalPages,
            'eta_seconds' => $etaSeconds,
            'error_message' => null,
            'download_url' => null,
            'updated_at' => now()->toIso8601String(),
        ], [
            'live' => $this->liveStatePayload(),
        ])));
    }

    protected function broadcastJobSnapshot(): void
    {
        $job = PdfJob::find($this->pdfJobId);
        if (! $job) {
            return;
        }

        event(new PdfJobUpdated(PdfJobPayloadFactory::fromModel($job)));
    }

    protected function notifyUserJobCompleted(PdfJob $job): void
    {
        if (! $job->user || $job->status !== 'completed') {
            return;
        }

        try {
            $downloadUrl = URL::temporarySignedRoute(
                'jobs.download.signed',
                now()->addHours(24),
                ['id' => $job->id]
            );

            $job->user->notify(new PdfJobCompletedNotification($job, $downloadUrl));
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] ProcessPdfJob: completion_email_failed', [
                'job_id' => $job->id,
                'user_id' => $job->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $runDiagnostics
     * @return array<string, mixed>
     */
    protected function buildOcrPersistenceAttributes(array $runDiagnostics): array
    {
        if (! $this->supportsOcrTrackingColumns()) {
            return [];
        }

        $ocr = is_array($runDiagnostics['extractor_summary']['ocr'] ?? null)
            ? $runDiagnostics['extractor_summary']['ocr']
            : [];
        $paddleOcr = is_array($ocr['paddleocr'] ?? null) ? $ocr['paddleocr'] : [];
        $local = is_array($ocr['local'] ?? null) ? $ocr['local'] : [];
        $candidatePages = is_array($ocr['candidate_pages'] ?? null) ? $ocr['candidate_pages'] : [];
        $pagesReplaced = is_array($ocr['pages_replaced'] ?? null) ? $ocr['pages_replaced'] : [];
        $providersUsed = array_values(array_filter(
            is_array($ocr['providers_used'] ?? null) ? $ocr['providers_used'] : [],
            static fn (mixed $value): bool => is_string($value) && $value !== ''
        ));

        $provider = null;
        if (($paddleOcr['pages_replaced'] ?? []) !== []) {
            $provider = 'paddleocr';
        } elseif (($local['pages_replaced'] ?? []) !== []) {
            $provider = 'tesseract';
        } elseif ($providersUsed !== []) {
            $provider = (string) $providersUsed[0];
        }

        $status = 'not_needed';
        if ($candidatePages !== []) {
            if ($pagesReplaced === $candidatePages || count($pagesReplaced) === count($candidatePages)) {
                $status = 'completed';
            } elseif ($pagesReplaced !== []) {
                $status = 'partial';
            } else {
                $status = (($paddleOcr['error'] ?? null) || (($local['pages_attempted'] ?? []) !== []))
                    ? 'failed'
                    : 'not_needed';
            }
        }

        $errorMessage = $paddleOcr['error'] ?? null;

        return [
            'ocr_provider' => $provider,
            'ocr_status' => $status,
            'ocr_job_id' => $paddleOcr['job_id'] ?? null,
            'ocr_started_at' => $this->normalizeDatabaseTimestamp($paddleOcr['started_at'] ?? null),
            'ocr_completed_at' => $this->normalizeDatabaseTimestamp($paddleOcr['completed_at'] ?? null),
            'ocr_result_path' => $paddleOcr['result_path'] ?? null,
            'ocr_error_message' => $errorMessage,
            'ocr_diagnostics' => $ocr !== [] ? $ocr : null,
        ];
    }

    protected function normalizeDatabaseTimestamp(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc()->format('Y-m-d H:i:s');
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] ProcessPdfJob: unable to normalize OCR timestamp', [
                'job_id' => $this->pdfJobId,
                'value' => $value,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function supportsOcrTrackingColumns(): bool
    {
        static $supportsColumns;

        if ($supportsColumns !== null) {
            return $supportsColumns;
        }

        try {
            $supportsColumns = Schema::hasColumns('pdf_jobs', [
                'ocr_provider',
                'ocr_status',
                'ocr_job_id',
                'ocr_started_at',
                'ocr_completed_at',
                'ocr_result_path',
                'ocr_error_message',
                'ocr_diagnostics',
            ]);
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] ProcessPdfJob: unable to inspect OCR tracking columns', [
                'job_id' => $this->pdfJobId,
                'message' => $e->getMessage(),
            ]);

            $supportsColumns = false;
        }

        return $supportsColumns;
    }

    protected function publishProcessingState(
        string $phase,
        string $label,
        string $message,
        ?int $progress = null,
        array $meta = []
    ): void {
        $this->currentLiveState = [
            'phase' => $phase,
            'label' => $label,
            'message' => $message,
            'detail' => is_string($meta['detail'] ?? null) ? trim((string) $meta['detail']) : null,
        ];

        if ($progress !== null) {
            $this->currentProgress = max($this->currentProgress, $progress);
        }

        if (array_key_exists('processed_pages', $meta) && is_numeric($meta['processed_pages'])) {
            $this->currentProcessedPages = max(0, (int) $meta['processed_pages']);
        }

        if (array_key_exists('total_pages', $meta) && is_numeric($meta['total_pages'])) {
            $this->currentTotalPages = max(0, (int) $meta['total_pages']);
        }

        if (array_key_exists('eta_seconds', $meta)) {
            $this->currentEtaSeconds = is_numeric($meta['eta_seconds']) ? (int) $meta['eta_seconds'] : null;
        }

        $update = [
            'status' => 'processing',
            'progress' => $this->currentProgress,
            'processed_pages' => $this->currentProcessedPages,
            'total_pages' => $this->currentTotalPages,
            'eta_seconds' => $this->currentEtaSeconds,
            'updated_at' => now(),
        ];

        if ($this->supportsOcrTrackingColumns()) {
            if (is_string($meta['ocr_provider'] ?? null) && trim((string) $meta['ocr_provider']) !== '') {
                $update['ocr_provider'] = trim((string) $meta['ocr_provider']);
            }

            if (is_string($meta['ocr_status'] ?? null) && trim((string) $meta['ocr_status']) !== '') {
                $update['ocr_status'] = trim((string) $meta['ocr_status']);
            }

            if (is_string($meta['ocr_job_id'] ?? null) && trim((string) $meta['ocr_job_id']) !== '') {
                $update['ocr_job_id'] = trim((string) $meta['ocr_job_id']);
            }

            if (array_key_exists('ocr_started_at', $meta)) {
                $update['ocr_started_at'] = $this->normalizeDatabaseTimestamp($meta['ocr_started_at']);
            }

            if (array_key_exists('ocr_error_message', $meta)) {
                $update['ocr_error_message'] = $meta['ocr_error_message'];
            }

            $update['ocr_diagnostics'] = ['live' => $this->liveStatePayload()];
        }

        DB::table('pdf_jobs')->where('id', $this->pdfJobId)->update($update);

        Log::info('[TenthLine] ProcessPdfJob: live processing state updated', [
            'job_id' => $this->pdfJobId,
            'phase' => $phase,
            'label' => $label,
            'message' => $message,
            'detail' => $this->currentLiveState['detail'],
            'progress' => $this->currentProgress,
            'processed_pages' => $this->currentProcessedPages,
            'total_pages' => $this->currentTotalPages,
        ]);

        event(new PdfJobUpdated(PdfJobPayloadFactory::withProcessingState([
            'id' => $this->pdfJobId,
            'status' => 'processing',
            'progress' => $this->currentProgress,
            'processed_pages' => $this->currentProcessedPages,
            'total_pages' => $this->currentTotalPages,
            'eta_seconds' => $this->currentEtaSeconds,
            'error_message' => null,
            'download_url' => null,
            'updated_at' => now()->toIso8601String(),
        ], [
            'live' => $this->liveStatePayload(),
        ])));
    }

    /**
     * @return array<string, mixed>
     */
    protected function liveStatePayload(): array
    {
        return array_filter([
            'phase' => $this->currentLiveState['phase'] ?? null,
            'label' => $this->currentLiveState['label'] ?? null,
            'message' => $this->currentLiveState['message'] ?? null,
            'detail' => $this->currentLiveState['detail'] ?? null,
            'updated_at' => now()->toIso8601String(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    protected function mapPageProgress(int $pageNo, int $totalPages, int $floor): int
    {
        if ($totalPages <= 0) {
            return max(0, min(96, $floor));
        }

        $start = max(12, min(90, $floor));
        $range = max(0, 96 - $start);
        $pageRatio = max(0, min(1, $pageNo / $totalPages));

        return max($start, min(96, (int) round($start + ($pageRatio * $range))));
    }
}

<?php

namespace App\Jobs;

use App\Events\PdfJobUpdated;
use App\Models\PdfJob;
use App\Services\PdfLineNumberService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const FIXED_LINE_INTERVAL = 10;

    public int $tries = 2;

    public int $timeout = 3600;

    public function __construct(
        public string $pdfJobId
    ) {}

    public function handle(PdfLineNumberService $pdfService): void
    {
        Log::info('[LegalLine] ProcessPdfJob handle() entered', ['job_id' => $this->pdfJobId]);

        $inputPath = Storage::disk('local')->path("pdf-jobs/{$this->pdfJobId}/input.pdf");
        $outputPath = Storage::disk('local')->path("pdf-jobs/{$this->pdfJobId}/output.pdf");

        Log::info('[LegalLine] ProcessPdfJob: Job started', [
            'job_id' => $this->pdfJobId,
            'input_path' => $inputPath,
            'output_path' => $outputPath,
        ]);

        if (! file_exists($inputPath)) {
            Log::error('[LegalLine] ProcessPdfJob: Input file not found', ['input_path' => $inputPath]);
            $this->failJob('Input file not found.');
            return;
        }

        DB::table('pdf_jobs')->where('id', $this->pdfJobId)->update([
            'status' => 'processing',
        ]);
        $this->broadcastJobSnapshot();

        $job = PdfJob::find($this->pdfJobId);
        if (! $job) {
            Log::warning('[LegalLine] ProcessPdfJob: PdfJob record not found', ['job_id' => $this->pdfJobId]);
            return;
        }

        Log::info('[LegalLine] ProcessPdfJob: Processing with options', [
            'job_id' => $this->pdfJobId,
            'line_interval' => self::FIXED_LINE_INTERVAL,
            'margin' => $job->margin ?? 'left',
            'font_size_pt' => $job->font_size_pt ?? 8,
        ]);

        $startTime = microtime(true);

        try {
            $totalPages = $pdfService->addLineNumbers(
                $inputPath,
                $outputPath,
                self::FIXED_LINE_INTERVAL,
                $job->margin ?? 'left',
                $job->font_size_pt ?? 8,
                function (int $pageNo, int $total) use ($startTime) {
                    $progress = $total > 0 ? (int) round(($pageNo / $total) * 100) : 0;
                    $elapsed = (int) (microtime(true) - $startTime);
                    $eta = $pageNo > 0 && $elapsed > 0
                        ? (int) (($elapsed / $pageNo) * ($total - $pageNo))
                        : 0;

                    DB::table('pdf_jobs')->where('id', $this->pdfJobId)->update([
                        'total_pages' => $total,
                        'processed_pages' => $pageNo,
                        'progress' => $progress,
                        'eta_seconds' => $eta,
                    ]);

                    Log::info('[LegalLine] ProcessPdfJob: Progress updated', [
                        'job_id' => $this->pdfJobId,
                        'processed_pages' => $pageNo,
                        'total_pages' => $total,
                        'progress' => $progress,
                        'eta_seconds' => $eta,
                    ]);

                    $this->broadcastProgress($progress, $pageNo, $total, $eta);
                }
            );

            $relativeOutput = "pdf-jobs/{$this->pdfJobId}/output.pdf";
            DB::table('pdf_jobs')->where('id', $this->pdfJobId)->update([
                'status' => 'completed',
                'total_pages' => $totalPages,
                'processed_pages' => $totalPages,
                'progress' => 100,
                'eta_seconds' => 0,
                'output_path' => $relativeOutput,
            ]);

            $duration = round(microtime(true) - $startTime, 2);
            Log::info('[LegalLine] ProcessPdfJob: Job completed successfully', [
                'job_id' => $this->pdfJobId,
                'total_pages' => $totalPages,
                'duration_seconds' => $duration,
                'output_path' => $relativeOutput,
            ]);
            $this->broadcastJobSnapshot();
        } catch (\Throwable $e) {
            Log::error('[LegalLine] ProcessPdfJob: Job failed with exception', [
                'job_id' => $this->pdfJobId,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->failJob($e->getMessage());
        }
    }

    protected function failJob(string $message): void
    {
        Log::warning('[LegalLine] ProcessPdfJob: Marking job as failed', ['job_id' => $this->pdfJobId, 'error_message' => $message]);
        DB::table('pdf_jobs')->where('id', $this->pdfJobId)->update([
            'status' => 'failed',
            'error_message' => $message,
        ]);
        $this->broadcastJobSnapshot();
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[LegalLine] ProcessPdfJob: Job failed (queue failed callback)', [
            'job_id' => $this->pdfJobId,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);
        $this->failJob($exception->getMessage());
    }

    protected function broadcastProgress(int $progress, int $processedPages, int $totalPages, int $etaSeconds): void
    {
        event(new PdfJobUpdated([
            'id' => $this->pdfJobId,
            'status' => 'processing',
            'progress' => $progress,
            'processed_pages' => $processedPages,
            'total_pages' => $totalPages,
            'eta_seconds' => $etaSeconds,
            'error_message' => null,
            'download_url' => null,
        ]));
    }

    protected function broadcastJobSnapshot(): void
    {
        $job = PdfJob::find($this->pdfJobId);
        if (! $job) {
            return;
        }

        event(new PdfJobUpdated([
            'id' => $job->id,
            'status' => $job->status,
            'progress' => (int) $job->progress,
            'processed_pages' => (int) $job->processed_pages,
            'total_pages' => (int) $job->total_pages,
            'eta_seconds' => $job->eta_seconds !== null ? (int) $job->eta_seconds : null,
            'error_message' => $job->error_message,
            'download_url' => $job->status === 'completed' && $job->output_path
                ? url("/api/job/{$job->id}/download")
                : null,
        ]));
    }
}

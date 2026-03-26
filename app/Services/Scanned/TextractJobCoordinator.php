<?php

namespace App\Services\Scanned;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;

class TextractJobCoordinator
{
    public function __construct(
        private readonly TextractClientFactory $clientFactory,
        private readonly TextractLineNormalizer $lineNormalizer
    ) {}

    public function enabled(): bool
    {
        return (bool) config('textract.enabled', false);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pageDimensions
     * @return array<string, mixed>
     */
    public function runSynchronous(string $pdfJobId, string $inputPath, array $pageDimensions = [], array $options = []): array
    {
        $startedAt = now();
        $started = $this->start($pdfJobId, $inputPath, $options);
        $completion = $this->awaitCompletion($started['job_id'], $pdfJobId, $options);

        if (! in_array($completion['status'], ['SUCCEEDED', 'PARTIAL_SUCCESS'], true)) {
            throw new RuntimeException('Textract job did not complete successfully: ' . ($completion['message'] ?? $completion['status']));
        }

        $this->reportProgress($options, [
            'phase' => 'ocr_fetching_results',
            'label' => 'Preparing the page text',
            'message' => 'We have finished reading the page text and are preparing it for accurate numbering.',
            'detail' => 'Organizing the page text for the next step.',
            'progress' => 44,
            'ocr_provider' => 'textract',
            'ocr_status' => 'fetching_results',
            'ocr_job_id' => $started['job_id'],
            'ocr_started_at' => $startedAt->toISOString(),
        ]);

        $normalizedPages = $this->withConfiguredMemoryLimit(
            fn (): array => $this->fetchNormalizedPages($started['job_id'], $pageDimensions, $options),
            $pdfJobId,
            $started['job_id']
        );

        $this->reportProgress($options, [
            'phase' => 'ocr_storing_results',
            'label' => 'Preparing the numbering layout',
            'message' => 'We are getting the document ready so the line numbers can be placed accurately.',
            'detail' => 'Saving the prepared page text.',
            'progress' => 50,
            'ocr_provider' => 'textract',
            'ocr_status' => 'storing_results',
            'ocr_job_id' => $started['job_id'],
            'ocr_started_at' => $startedAt->toISOString(),
        ]);

        $resultPath = $this->storeNormalizedPages($pdfJobId, $normalizedPages, $options);
        $deletedSource = false;
        $deleteSourceAfterCompletion = (bool) config('textract.delete_source_after_completion', true);
        $keepSourceInS3 = (bool) config('textract.keep_source_in_s3', false);

        if ($deleteSourceAfterCompletion && ! $keepSourceInS3) {
            $this->reportProgress($options, [
                'phase' => 'ocr_cleaning_up',
                'label' => 'Final checks',
                'message' => 'We are wrapping up the text preparation step.',
                'detail' => 'Tidying up temporary working files.',
                'progress' => 54,
                'ocr_provider' => 'textract',
                'ocr_status' => 'cleaning_up',
                'ocr_job_id' => $started['job_id'],
                'ocr_started_at' => $startedAt->toISOString(),
            ]);

            try {
                $this->deleteSourceDocument($started['source_key'], $options);
                $deletedSource = true;
            } catch (\Throwable $e) {
                Log::warning('[LegalLine] TextractJobCoordinator: source cleanup failed', [
                    'pdf_job_id' => $pdfJobId,
                    'job_id' => $started['job_id'],
                    'source_disk' => $started['source_disk'],
                    'source_key' => $started['source_key'],
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $completedAt = now();

        $this->reportProgress($options, [
            'phase' => 'ocr_ready_for_numbering',
            'label' => 'Starting line numbering',
            'message' => 'The page text is ready, and we are about to place the line numbers.',
            'detail' => 'Line numbering will begin shortly.',
            'progress' => 58,
            'ocr_provider' => 'textract',
            'ocr_status' => 'completed',
            'ocr_job_id' => $started['job_id'],
            'ocr_started_at' => $startedAt->toISOString(),
        ]);

        Log::info('[LegalLine] TextractJobCoordinator: synchronous run completed', [
            'pdf_job_id' => $pdfJobId,
            'job_id' => $started['job_id'],
            'status' => $completion['status'],
            'poll_attempts' => $completion['attempts'],
            'pages_normalized' => count($normalizedPages),
            'result_path' => $resultPath,
            'deleted_source' => $deletedSource,
            'duration_seconds' => round($completedAt->floatDiffInSeconds($startedAt), 3),
        ]);

        return [
            'job_id' => $started['job_id'],
            'bucket' => $started['bucket'],
            'source_disk' => $started['source_disk'],
            'source_key' => $started['source_key'],
            'client_request_token' => $started['client_request_token'],
            'status' => $completion['status'],
            'status_message' => $completion['message'],
            'warnings' => $completion['warnings'],
            'poll_attempts' => $completion['attempts'],
            'started_at' => $startedAt->toISOString(),
            'completed_at' => $completedAt->toISOString(),
            'result_path' => $resultPath,
            'pages' => $normalizedPages,
            'source_deleted' => $deletedSource,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function start(string $pdfJobId, string $inputPath, array $options = []): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Textract is disabled.');
        }

        if (! is_file($inputPath)) {
            throw new RuntimeException('Textract input PDF not found.');
        }

        $sourceDisk = (string) ($options['source_disk'] ?? config('textract.source_disk', 'textract'));
        $sourceKey = $this->buildSourceKey($pdfJobId, $options);
        $bucket = $this->resolveBucketName($sourceDisk, $options);
        $stream = @fopen($inputPath, 'r');

        if ($stream === false) {
            throw new RuntimeException('Unable to open PDF for Textract upload.');
        }

        Log::info('[LegalLine] TextractJobCoordinator: uploading source document', [
            'pdf_job_id' => $pdfJobId,
            'input_path' => $inputPath,
            'source_disk' => $sourceDisk,
            'bucket' => $bucket,
            'source_key' => $sourceKey,
            'file_size_bytes' => is_file($inputPath) ? filesize($inputPath) : null,
        ]);

        $this->reportProgress($options, [
            'phase' => 'ocr_uploading_source',
            'label' => 'Preparing the document',
            'message' => 'This document appears to be image-based, so we are preparing it for a careful reading pass.',
            'detail' => 'Sending the file for detailed text reading.',
            'progress' => 14,
            'ocr_provider' => 'textract',
            'ocr_status' => 'uploading_source',
        ]);

        try {
            Storage::disk($sourceDisk)->put($sourceKey, $stream);
        } finally {
            fclose($stream);
        }

        $clientRequestToken = (string) ($options['client_request_token'] ?? sha1('textract:' . $pdfJobId));
        $payload = [
            'ClientRequestToken' => substr($clientRequestToken, 0, 64),
            'JobTag' => substr((string) ($options['job_tag'] ?? $pdfJobId), 0, 64),
            'DocumentLocation' => [
                'S3Object' => [
                    'Bucket' => $bucket,
                    'Name' => $sourceKey,
                ],
            ],
        ];

        $topicArn = trim((string) config('textract.sns_topic_arn', ''));
        $roleArn = trim((string) config('textract.sns_role_arn', ''));
        if ((bool) config('textract.use_sns', false) && $topicArn !== '' && $roleArn !== '') {
            $payload['NotificationChannel'] = [
                'SNSTopicArn' => $topicArn,
                'RoleArn' => $roleArn,
            ];
        }

        Log::info('[LegalLine] TextractJobCoordinator: starting Textract job', [
            'pdf_job_id' => $pdfJobId,
            'bucket' => $bucket,
            'source_key' => $sourceKey,
            'source_disk' => $sourceDisk,
            'using_sns_notification' => isset($payload['NotificationChannel']),
        ]);

        $this->reportProgress($options, [
            'phase' => 'ocr_starting_job',
            'label' => 'Starting the careful reading step',
            'message' => 'We are beginning a closer reading pass so the line numbers land in the right places.',
            'detail' => 'Starting the page-reading step.',
            'progress' => 18,
            'ocr_provider' => 'textract',
            'ocr_status' => 'starting_job',
        ]);

        $response = $this->clientFactory->make()->startDocumentTextDetection($payload)->toArray();
        $jobId = trim((string) ($response['JobId'] ?? ''));

        if ($jobId === '') {
            throw new RuntimeException('Textract did not return a JobId.');
        }

        Log::info('[LegalLine] TextractJobCoordinator: Textract job started', [
            'pdf_job_id' => $pdfJobId,
            'job_id' => $jobId,
            'bucket' => $bucket,
            'source_key' => $sourceKey,
        ]);

        $this->reportProgress($options, [
            'phase' => 'ocr_polling',
            'label' => 'Reading the page text carefully',
            'message' => 'We are reading the text from each page carefully. This can take a little longer for image-based PDFs.',
            'detail' => 'The document is still being reviewed.',
            'progress' => 22,
            'ocr_provider' => 'textract',
            'ocr_status' => 'processing',
            'ocr_job_id' => $jobId,
            'ocr_started_at' => now()->toISOString(),
        ]);

        return [
            'job_id' => $jobId,
            'bucket' => $bucket,
            'source_disk' => $sourceDisk,
            'source_key' => $sourceKey,
            'client_request_token' => substr($clientRequestToken, 0, 64),
            'raw' => $response,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkStatus(string $jobId): array
    {
        $response = $this->clientFactory->make()->getDocumentTextDetection([
            'JobId' => $jobId,
            'MaxResults' => 1,
        ])->toArray();

        return [
            'status' => (string) ($response['JobStatus'] ?? 'UNKNOWN'),
            'message' => $response['StatusMessage'] ?? null,
            'warnings' => $response['Warnings'] ?? [],
            'raw' => $response,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function awaitCompletion(string $jobId, string $pdfJobId, array $options = []): array
    {
        $maxAttempts = max(1, (int) ($options['max_poll_attempts'] ?? config('textract.max_poll_attempts', 120)));
        $delaySeconds = max(0, (int) ($options['poll_delay_seconds'] ?? config('textract.poll_delay_seconds', 10)));
        $lastStatus = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $status = $this->checkStatus($jobId);
            $lastStatus = $status;

            Log::info('[LegalLine] TextractJobCoordinator: poll status', [
                'pdf_job_id' => $pdfJobId,
                'job_id' => $jobId,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'status' => $status['status'],
                'message' => $status['message'],
            ]);

            $this->reportProgress($options, [
                'phase' => 'ocr_polling',
                'label' => 'Reading the page text carefully',
                'message' => $status['status'] === 'IN_PROGRESS'
                    ? 'We are still reading the page text carefully. This step can take a little longer for image-based PDFs.'
                    : 'We have finished reading the page text and are moving to the next step.',
                'detail' => $status['status'] === 'IN_PROGRESS'
                    ? 'Still working through the document.'
                    : 'The document has been fully read.',
                'progress' => min(42, 22 + (($attempt - 1) * 4)),
                'ocr_provider' => 'textract',
                'ocr_status' => strtolower((string) $status['status']),
                'ocr_job_id' => $jobId,
            ]);

            if (in_array($status['status'], ['SUCCEEDED', 'PARTIAL_SUCCESS', 'FAILED'], true)) {
                return $status + ['attempts' => $attempt];
            }

            if ($attempt < $maxAttempts && $delaySeconds > 0) {
                sleep($delaySeconds);
            }
        }

        throw new RuntimeException('Timed out waiting for Textract job [' . $jobId . '] after ' . $maxAttempts . ' attempts.');
    }

    /**
     * @return array{status: string, blocks: list<array<string, mixed>>, warnings: array<int, mixed>, pages: int}
     */
    public function fetchBlocks(string $jobId): array
    {
        $nextToken = null;
        $blocks = [];
        $status = 'UNKNOWN';
        $warnings = [];
        $pages = 0;

        do {
            $payload = [
                'JobId' => $jobId,
                'MaxResults' => (int) config('textract.max_results', 1000),
            ];

            if ($nextToken !== null) {
                $payload['NextToken'] = $nextToken;
            }

            $response = $this->clientFactory->make()->getDocumentTextDetection($payload)->toArray();
            $status = (string) ($response['JobStatus'] ?? $status);
            $warnings = is_array($response['Warnings'] ?? null) ? $response['Warnings'] : $warnings;
            $pages = max($pages, (int) ($response['DocumentMetadata']['Pages'] ?? 0));

            foreach (($response['Blocks'] ?? []) as $block) {
                if (is_array($block)) {
                    $blocks[] = $block;
                }
            }

            $nextToken = isset($response['NextToken']) && is_string($response['NextToken']) && $response['NextToken'] !== ''
                ? $response['NextToken']
                : null;
        } while ($nextToken !== null);

        Log::info('[LegalLine] TextractJobCoordinator: fetched Textract blocks', [
            'job_id' => $jobId,
            'status' => $status,
            'block_count' => count($blocks),
            'pages' => $pages,
            'warnings_count' => count($warnings),
        ]);

        return [
            'status' => $status,
            'blocks' => $blocks,
            'warnings' => $warnings,
            'pages' => $pages,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pageDimensions
     * @return array<int, array<string, mixed>>
     */
    public function fetchNormalizedPages(string $jobId, array $pageDimensions = [], array $options = []): array
    {
        $this->reportProgress($options, [
            'phase' => 'ocr_fetching_results',
            'label' => 'Preparing the page text',
            'message' => 'We have finished reading the page text and are preparing it for accurate numbering.',
            'detail' => 'Gathering the page text for the next step.',
            'progress' => 44,
            'ocr_provider' => 'textract',
            'ocr_status' => 'fetching_results',
            'ocr_job_id' => $jobId,
        ]);

        $response = $this->fetchBlocks($jobId);

        if (! in_array($response['status'], ['SUCCEEDED', 'PARTIAL_SUCCESS'], true)) {
            throw new RuntimeException('Textract job is not ready for normalization.');
        }

        $this->reportProgress($options, [
            'phase' => 'ocr_normalizing_results',
            'label' => 'Lining up the page text',
            'message' => 'We are lining up the page text so the line numbers follow the document correctly.',
            'detail' => 'Matching the page text to the page layout.',
            'progress' => 47,
            'ocr_provider' => 'textract',
            'ocr_status' => 'normalizing_results',
            'ocr_job_id' => $jobId,
        ]);

        $pages = $this->lineNormalizer->normalize($response['blocks'], $pageDimensions, $options);

        Log::info('[LegalLine] TextractJobCoordinator: normalized Textract pages', [
            'job_id' => $jobId,
            'page_count' => count($pages),
            'page_numbers' => array_keys($pages),
        ]);

        return $pages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    public function storeNormalizedPages(string $pdfJobId, array $pages, array $options = []): string
    {
        $disk = (string) ($options['result_disk'] ?? config('textract.result_disk', 'local'));
        $path = $this->buildResultPath($pdfJobId, $options);
        $payload = json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new RuntimeException('Unable to encode normalized Textract pages as JSON.');
        }

        Storage::disk($disk)->put($path, $payload);

        Log::info('[LegalLine] TextractJobCoordinator: stored normalized pages', [
            'pdf_job_id' => $pdfJobId,
            'result_disk' => $disk,
            'result_path' => $path,
            'page_count' => count($pages),
        ]);

        return $path;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadNormalizedPages(string $path, array $options = []): array
    {
        $disk = (string) ($options['result_disk'] ?? config('textract.result_disk', 'local'));

        if (! Storage::disk($disk)->exists($path)) {
            throw new RuntimeException('Normalized Textract result file not found.');
        }

        $payload = Storage::disk($disk)->get($path);
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Normalized Textract result file is not valid JSON.');
        }

        Log::debug('[LegalLine] TextractJobCoordinator: loaded normalized pages', [
            'result_disk' => $disk,
            'result_path' => $path,
            'page_count' => count($decoded),
        ]);

        return $decoded;
    }

    public function deleteSourceDocument(string $path, array $options = []): void
    {
        $disk = (string) ($options['source_disk'] ?? config('textract.source_disk', 'textract'));
        Storage::disk($disk)->delete($path);

        Log::info('[LegalLine] TextractJobCoordinator: deleted source document', [
            'source_disk' => $disk,
            'source_key' => $path,
        ]);
    }

    /**
     * @return array<int, array{width: float, height: float}>
     */
    public function resolvePageDimensions(string $inputPath): array
    {
        if (! is_file($inputPath)) {
            return [];
        }

        try {
            $pdf = new Fpdi('P', 'pt');
            $pageCount = $pdf->setSourceFile($inputPath);
            $dimensions = [];

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                $dimensions[$pageNo] = [
                    'width' => (float) ($size['width'] ?? config('textract.default_page_width_pt', 612.0)),
                    'height' => (float) ($size['height'] ?? config('textract.default_page_height_pt', 792.0)),
                ];
            }

            Log::debug('[LegalLine] TextractJobCoordinator: resolved page dimensions', [
                'input_path' => $inputPath,
                'page_count' => count($dimensions),
            ]);

            return $dimensions;
        } catch (\Throwable $e) {
            Log::warning('[LegalLine] TextractJobCoordinator: failed to resolve page dimensions', [
                'input_path' => $inputPath,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function buildSourceKey(string $pdfJobId, array $options): string
    {
        $prefix = trim((string) ($options['prefix'] ?? config('textract.prefix', 'textract/input')), '/');

        return $prefix . '/' . $pdfJobId . '/input.pdf';
    }

    private function buildResultPath(string $pdfJobId, array $options): string
    {
        $prefix = trim((string) ($options['result_prefix'] ?? config('textract.result_prefix', 'pdf-jobs')), '/');

        return $prefix . '/' . $pdfJobId . '/textract-pages.json';
    }

    private function reportProgress(array $options, array $state): void
    {
        $callback = $options['progress_callback'] ?? null;
        if (! is_callable($callback)) {
            return;
        }

        try {
            $callback($state);
        } catch (\Throwable $e) {
            Log::warning('[LegalLine] TextractJobCoordinator: progress callback failed', [
                'phase' => $state['phase'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function resolveBucketName(string $disk, array $options): string
    {
        $bucket = trim((string) ($options['bucket'] ?? config('filesystems.disks.' . $disk . '.bucket', config('textract.bucket', ''))));

        if ($bucket === '') {
            throw new RuntimeException('Textract bucket is not configured for disk [' . $disk . '].');
        }

        return $bucket;
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function withConfiguredMemoryLimit(callable $callback, string $pdfJobId, string $jobId)
    {
        $configuredLimit = trim((string) config('textract.memory_limit', ''));
        if ($configuredLimit === '') {
            return $callback();
        }

        $originalLimit = ini_get('memory_limit');
        $applied = $originalLimit !== false && $originalLimit !== $configuredLimit;

        if ($applied) {
            @ini_set('memory_limit', $configuredLimit);

            Log::info('[LegalLine] TextractJobCoordinator: raised memory limit for result fetch', [
                'pdf_job_id' => $pdfJobId,
                'job_id' => $jobId,
                'original_memory_limit' => $originalLimit,
                'configured_memory_limit' => $configuredLimit,
            ]);
        }

        try {
            return $callback();
        } finally {
            if ($applied && $originalLimit !== false) {
                @ini_set('memory_limit', (string) $originalLimit);
            }
        }
    }
}

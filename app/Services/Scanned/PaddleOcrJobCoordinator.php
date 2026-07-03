<?php

namespace App\Services\Scanned;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;

class PaddleOcrJobCoordinator
{
    public function __construct(
        private readonly PaddleOcrLineNormalizer $lineNormalizer
    ) {}

    public function enabled(): bool
    {
        return (bool) config('paddleocr.enabled', false);
    }

    /**
     * @param  array<int, array{width: float, height: float}>  $pageDimensions
     * @param  list<int>  $candidatePages
     * @return array<string, mixed>
     */
    public function run(string $pdfJobId, string $inputPath, array $pageDimensions = [], array $candidatePages = [], array $options = []): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('PaddleOCR is disabled.');
        }

        if (! is_file($inputPath)) {
            throw new RuntimeException('PaddleOCR input PDF not found.');
        }

        $startedAt = now();
        $runId = 'paddleocr-' . substr(sha1($pdfJobId . '|' . $inputPath . '|' . microtime(true)), 0, 16);
        $resultPages = [];
        $warnings = [];
        $candidatePages = array_values(array_unique(array_filter($candidatePages, static fn (mixed $pageNo): bool => is_int($pageNo) && $pageNo > 0)));
        sort($candidatePages);
        $totalCandidates = count($candidatePages);

        if ($candidatePages === []) {
            return [
                'status' => 'SUCCEEDED',
                'job_id' => $runId,
                'started_at' => $startedAt->toISOString(),
                'completed_at' => now()->toISOString(),
                'result_path' => $this->storeNormalizedPages($pdfJobId, [], $options),
                'pages' => [],
                'pages_attempted' => [],
                'warnings' => [],
            ];
        }

        $this->reportProgress($options, [
            'phase' => 'ocr_rendering_pages',
            'label' => 'Preparing scanned pages',
            'message' => 'We are preparing the scanned pages for OCR.',
            'detail' => "Preparing {$totalCandidates} page(s) for PaddleOCR.",
            'progress' => 14,
            'ocr_provider' => 'paddleocr',
            'ocr_status' => 'rendering_pages',
            'ocr_job_id' => $runId,
            'ocr_started_at' => $startedAt->toISOString(),
        ]);

        // Phase 1: rasterize every candidate page up front so the OCR requests
        // can then be dispatched concurrently.
        $renderedPages = [];

        try {
            foreach ($candidatePages as $pageNo) {
                if (! is_array($pageDimensions[$pageNo] ?? null)) {
                    $warnings[] = "Missing page dimensions for page {$pageNo}.";
                    continue;
                }

                $renderedPages[$pageNo] = $this->renderPageToImage($inputPath, $pageNo);
            }

            // Phase 2: OCR the rendered pages in concurrent batches. The sidecar
            // is CPU-bound per page, so run it with matching worker processes
            // (uvicorn --workers N) to realize the parallelism.
            $concurrency = max(1, (int) config('paddleocr.concurrency', 2));
            $pageNumbers = array_keys($renderedPages);
            $totalPages = count($pageNumbers);
            $processed = 0;

            foreach (array_chunk($pageNumbers, $concurrency) as $chunk) {
                $chunkLabel = implode(', ', $chunk);
                $this->reportProgress($options, [
                    'phase' => 'ocr_processing_pages',
                    'label' => 'Reading scanned pages',
                    'message' => 'We are reading the scanned pages with PaddleOCR.',
                    'detail' => "Processing page(s) {$chunkLabel} ({$processed} of {$totalPages} done).",
                    'progress' => min(46, 18 + (int) floor(($processed / max(1, $totalPages)) * 28)),
                    'ocr_provider' => 'paddleocr',
                    'ocr_status' => 'processing',
                    'ocr_job_id' => $runId,
                    'ocr_started_at' => $startedAt->toISOString(),
                ]);

                $responses = $this->requestOcrPool($chunk, $renderedPages);

                foreach ($chunk as $pageNo) {
                    $ocrData = $this->parseOcrResponse($responses["page-{$pageNo}"] ?? null, $pageNo);
                    $pageDimension = $pageDimensions[$pageNo];
                    $rendered = $renderedPages[$pageNo];

                    $normalizedPage = $this->lineNormalizer->normalizePage(
                        $ocrData,
                        [
                            'width' => (float) ($pageDimension['width'] ?? 612.0),
                            'height' => (float) ($pageDimension['height'] ?? 792.0),
                        ],
                        (int) $rendered['image_width'],
                        (int) $rendered['image_height'],
                        $pageNo,
                        $options
                    );

                    $normalizedPage['page_rotation'] = (int) ($pageDimension['rotation'] ?? 0);
                    $resultPages[$pageNo] = $normalizedPage;
                    $processed++;
                }
            }
        } finally {
            foreach ($renderedPages as $rendered) {
                @unlink($rendered['image_path']);
            }
        }

        ksort($resultPages);

        $this->reportProgress($options, [
            'phase' => 'ocr_storing_results',
            'label' => 'Preparing numbering layout',
            'message' => 'We are preparing the OCR results for line numbering.',
            'detail' => 'Saving normalized OCR page data.',
            'progress' => 50,
            'ocr_provider' => 'paddleocr',
            'ocr_status' => 'storing_results',
            'ocr_job_id' => $runId,
            'ocr_started_at' => $startedAt->toISOString(),
        ]);

        $resultPath = $this->storeNormalizedPages($pdfJobId, $resultPages, $options);
        $completedAt = now();
        $durationSeconds = max(0.0, round(($completedAt->valueOf() - $startedAt->valueOf()) / 1000, 3));

        $this->reportProgress($options, [
            'phase' => 'ocr_ready_for_numbering',
            'label' => 'Starting line numbering',
            'message' => 'The scanned page text is ready, and line numbering is about to begin.',
            'detail' => 'PaddleOCR processing completed.',
            'progress' => 58,
            'ocr_provider' => 'paddleocr',
            'ocr_status' => 'completed',
            'ocr_job_id' => $runId,
            'ocr_started_at' => $startedAt->toISOString(),
        ]);

        Log::info('[TenthLine] PaddleOcrJobCoordinator: OCR run completed', [
            'pdf_job_id' => $pdfJobId,
            'job_id' => $runId,
            'candidate_pages' => $candidatePages,
            'pages_normalized' => array_keys($resultPages),
            'result_path' => $resultPath,
            'warnings' => $warnings,
            'duration_seconds' => $durationSeconds,
        ]);

        return [
            'status' => 'SUCCEEDED',
            'job_id' => $runId,
            'started_at' => $startedAt->toISOString(),
            'completed_at' => $completedAt->toISOString(),
            'result_path' => $resultPath,
            'pages' => $resultPages,
            'pages_attempted' => $candidatePages,
            'warnings' => $warnings,
        ];
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
                    'width' => (float) ($size['width'] ?? 612.0),
                    'height' => (float) ($size['height'] ?? 792.0),
                ];
            }

            return $dimensions;
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] PaddleOcrJobCoordinator: failed to resolve page dimensions', [
                'input_path' => $inputPath,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{image_path: string, image_width: int, image_height: int}
     */
    private function renderPageToImage(string $inputPath, int $pageNo): array
    {
        $temporaryBase = tempnam(sys_get_temp_dir(), 'tenthline-paddle-');
        if ($temporaryBase === false) {
            throw new RuntimeException('Unable to create a temporary image path for PaddleOCR.');
        }

        @unlink($temporaryBase);

        $imageBase = $temporaryBase . '-page';
        $imagePath = $imageBase . '.png';
        $renderDpi = max(180, min(600, (int) config('paddleocr.render_dpi', 300)));
        $command = sprintf(
            '%s -f %d -l %d -singlefile -gray -r %d -png %s %s 2>/dev/null',
            escapeshellcmd((string) config('line_numbering.pdftoppm_binary', 'pdftoppm')),
            $pageNo,
            $pageNo,
            $renderDpi,
            escapeshellarg($inputPath),
            escapeshellarg($imageBase)
        );

        shell_exec($command);

        if (! is_file($imagePath)) {
            throw new RuntimeException("Unable to rasterize page {$pageNo} for PaddleOCR.");
        }

        [$imageWidth, $imageHeight] = getimagesize($imagePath) ?: [0, 0];
        if ($imageWidth <= 0 || $imageHeight <= 0) {
            @unlink($imagePath);
            throw new RuntimeException("Unable to determine image size for rasterized page {$pageNo}.");
        }

        return [
            'image_path' => $imagePath,
            'image_width' => (int) $imageWidth,
            'image_height' => (int) $imageHeight,
        ];
    }

    /**
     * Dispatch one OCR request per page concurrently.
     *
     * @param  list<int>  $pageNumbers
     * @param  array<int, array{image_path: string, image_width: int, image_height: int}>  $renderedPages
     * @return array<string, mixed> responses keyed by "page-{$pageNo}"
     */
    private function requestOcrPool(array $pageNumbers, array $renderedPages): array
    {
        $urls = $this->serviceUrls();
        $timeoutSeconds = max(5, (int) config('paddleocr.timeout_seconds', 60));
        $minConfidence = max(0.0, min(1.0, (float) config('paddleocr.min_confidence', 0.0)));
        $query = '/ocr/page?min_confidence=' . urlencode((string) $minConfidence);

        return Http::pool(function (Pool $pool) use ($pageNumbers, $renderedPages, $urls, $query, $timeoutSeconds): array {
            $requests = [];

            foreach (array_values($pageNumbers) as $index => $pageNo) {
                $imagePath = $renderedPages[$pageNo]['image_path'];
                $endpoint = $urls[$index % count($urls)] . $query;
                $requests[] = $pool->as("page-{$pageNo}")
                    ->timeout($timeoutSeconds)
                    ->connectTimeout(min(10, $timeoutSeconds))
                    ->attach('file', (string) file_get_contents($imagePath), basename($imagePath))
                    ->post($endpoint);
            }

            return $requests;
        });
    }

    /**
     * @return list<string>
     */
    private function serviceUrls(): array
    {
        $urls = array_values(array_filter(array_map(
            static fn (string $url): string => rtrim(trim($url), '/'),
            explode(',', (string) config('paddleocr.urls', ''))
        ), static fn (string $url): bool => $url !== ''));

        if ($urls !== []) {
            return $urls;
        }

        return [rtrim((string) config('paddleocr.url', 'http://127.0.0.1:8765'), '/')];
    }

    /**
     * @return array{success?: bool, lines?: list<array<string, mixed>>, error?: string}
     */
    private function parseOcrResponse(mixed $response, int $pageNo): array
    {
        if ($response instanceof \Throwable) {
            throw new RuntimeException("PaddleOCR request for page {$pageNo} failed: " . $response->getMessage(), 0, $response);
        }

        if (! $response instanceof Response) {
            throw new RuntimeException("PaddleOCR request for page {$pageNo} produced no response.");
        }

        if (! $response->successful()) {
            throw new RuntimeException("PaddleOCR request for page {$pageNo} failed with HTTP status " . $response->status() . '.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException("PaddleOCR response for page {$pageNo} was not valid JSON.");
        }

        if (($payload['success'] ?? true) !== true) {
            throw new RuntimeException((string) ($payload['error'] ?? "PaddleOCR OCR request for page {$pageNo} failed."));
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     */
    private function storeNormalizedPages(string $pdfJobId, array $pages, array $options = []): string
    {
        $disk = (string) ($options['result_disk'] ?? 'local');
        $path = "pdf-jobs/{$pdfJobId}/ocr-results.json";
        Storage::disk($disk)->put($path, json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
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
            Log::warning('[TenthLine] PaddleOcrJobCoordinator: progress callback failed', [
                'phase' => $state['phase'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }
}

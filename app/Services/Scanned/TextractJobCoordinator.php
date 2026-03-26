<?php

namespace App\Services\Scanned;

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

        $response = $this->clientFactory->make()->startDocumentTextDetection($payload)->toArray();
        $jobId = trim((string) ($response['JobId'] ?? ''));

        if ($jobId === '') {
            throw new RuntimeException('Textract did not return a JobId.');
        }

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
        $response = $this->fetchBlocks($jobId);

        if (! in_array($response['status'], ['SUCCEEDED', 'PARTIAL_SUCCESS'], true)) {
            throw new RuntimeException('Textract job is not ready for normalization.');
        }

        return $this->lineNormalizer->normalize($response['blocks'], $pageDimensions, $options);
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

        return $decoded;
    }

    public function deleteSourceDocument(string $path, array $options = []): void
    {
        $disk = (string) ($options['source_disk'] ?? config('textract.source_disk', 'textract'));
        Storage::disk($disk)->delete($path);
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

            return $dimensions;
        } catch (\Throwable) {
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

    private function resolveBucketName(string $disk, array $options): string
    {
        $bucket = trim((string) ($options['bucket'] ?? config('filesystems.disks.' . $disk . '.bucket', config('textract.bucket', ''))));

        if ($bucket === '') {
            throw new RuntimeException('Textract bucket is not configured for disk [' . $disk . '].');
        }

        return $bucket;
    }
}

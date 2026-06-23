<?php

namespace App\Console\Commands;

use App\Services\Scanned\TextractJobCoordinator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Fpdi;

class TextractHealthCheckCommand extends Command
{
    protected $signature = 'textract:health-check
        {--skip-textract : Only verify S3 access and skip the Textract OCR smoke test}
        {--keep-artifacts : Keep generated test artifacts for debugging}
        {--poll-delay= : Override Textract poll delay seconds for this run}
        {--max-attempts= : Override Textract max poll attempts for this run}';

    protected $description = 'Verify S3 and AWS Textract connectivity using the app\'s production configuration';

    public function handle(TextractJobCoordinator $textractJobCoordinator): int
    {
        $sourceDisk = (string) config('textract.source_disk', 'textract');
        $resultDisk = (string) config('textract.result_disk', 'local');
        $bucket = (string) config('filesystems.disks.' . $sourceDisk . '.bucket', config('textract.bucket', ''));
        $region = (string) config('textract.region', 'us-east-1');
        $keepArtifacts = (bool) $this->option('keep-artifacts');

        $this->info('Starting AWS Textract health check.');
        $this->line('Source disk: ' . $sourceDisk);
        $this->line('Result disk: ' . $resultDisk);
        $this->line('Bucket: ' . $bucket);
        $this->line('Region: ' . $region);

        if ($bucket === '') {
            $this->error('Textract bucket is not configured.');

            return self::FAILURE;
        }

        try {
            $s3Summary = $this->runS3SmokeCheck($sourceDisk);
            $this->info('S3 smoke check passed.');
            $this->line('S3 key: ' . $s3Summary['key']);
            $this->line('S3 size: ' . $s3Summary['size'] . ' bytes');
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] TextractHealthCheckCommand: S3 smoke check failed', [
                'source_disk' => $sourceDisk,
                'bucket' => $bucket,
                'message' => $e->getMessage(),
            ]);

            $this->error('S3 smoke check failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('skip-textract')) {
            $this->info('Textract smoke test skipped by option.');

            return self::SUCCESS;
        }

        if (! $textractJobCoordinator->enabled()) {
            $this->error('Textract is disabled. Set TEXTRACT_ENABLED=true to run the full health check.');

            return self::FAILURE;
        }

        $pdfPath = $this->createHealthCheckPdf();
        $jobId = 'healthcheck-' . Str::lower(Str::random(12));
        $options = [
            'prefix' => 'textract/healthcheck/input',
            'result_prefix' => 'pdf-jobs/healthcheck',
        ];

        if ($this->option('poll-delay') !== null) {
            $options['poll_delay_seconds'] = max(0, (int) $this->option('poll-delay'));
        }

        if ($this->option('max-attempts') !== null) {
            $options['max_poll_attempts'] = max(1, (int) $this->option('max-attempts'));
        }

        $run = null;

        try {
            $pageDimensions = $textractJobCoordinator->resolvePageDimensions($pdfPath);
            $run = $textractJobCoordinator->runSynchronous($jobId, $pdfPath, $pageDimensions, $options);
            $pages = is_array($run['pages'] ?? null) ? $run['pages'] : [];
            $pageCount = count($pages);
            $lineCount = array_sum(array_map(
                static fn (array $page): int => count(is_array($page['raw_lines'] ?? null) ? $page['raw_lines'] : []),
                $pages
            ));

            if (($run['status'] ?? null) !== 'SUCCEEDED') {
                throw new \RuntimeException('Textract returned status [' . ($run['status'] ?? 'UNKNOWN') . '].');
            }

            if ($pageCount === 0 || $lineCount === 0) {
                throw new \RuntimeException('Textract completed but returned no normalized lines.');
            }

            $this->info('Textract smoke test passed.');
            $this->line('Textract job id: ' . (string) ($run['job_id'] ?? 'n/a'));
            $this->line('Normalized pages: ' . $pageCount);
            $this->line('Normalized lines: ' . $lineCount);
            $this->line('Result path: ' . (string) ($run['result_path'] ?? 'n/a'));
        } catch (\Throwable $e) {
            Log::warning('[TenthLine] TextractHealthCheckCommand: Textract smoke test failed', [
                'job_id' => $jobId,
                'message' => $e->getMessage(),
            ]);

            $this->error('Textract smoke test failed: ' . $e->getMessage());

            if (! $keepArtifacts) {
                $this->cleanupArtifacts($run, $pdfPath, $sourceDisk, $resultDisk);
            }

            return self::FAILURE;
        }

        if (! $keepArtifacts) {
            $this->cleanupArtifacts($run, $pdfPath, $sourceDisk, $resultDisk);
        } else {
            $this->line('Keeping generated health-check artifacts for debugging.');
            $this->line('Local PDF: ' . $pdfPath);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{key: string, size: int}
     */
    private function runS3SmokeCheck(string $disk): array
    {
        $key = 'textract/healthcheck/s3-' . Str::uuid() . '.txt';
        $payload = 'textract-health-check:' . now()->toISOString();

        Storage::disk($disk)->put($key, $payload);

        $contents = Storage::disk($disk)->get($key);
        $size = Storage::disk($disk)->size($key);

        if ($contents !== $payload) {
            throw new \RuntimeException('S3 round-trip payload mismatch.');
        }

        Storage::disk($disk)->delete($key);

        return [
            'key' => $key,
            'size' => (int) $size,
        ];
    }

    private function createHealthCheckPdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'textract-healthcheck-');
        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary PDF path for the health check.');
        }

        $pdf = new Fpdi('P', 'pt');
        $pdf->AddPage('P', [612.0, 792.0]);
        $pdf->SetFont('Helvetica', '', 18);
        $pdf->Text(72.0, 120.0, 'Textract health check');
        $pdf->Text(72.0, 150.0, 'This page confirms AWS OCR access.');
        $pdf->Text(72.0, 180.0, now()->toIso8601String());
        $pdf->Output('F', $path);

        return $path;
    }

    /**
     * @param  array<string, mixed>|null  $run
     */
    private function cleanupArtifacts(?array $run, string $pdfPath, string $sourceDisk, string $resultDisk): void
    {
        if (is_file($pdfPath)) {
            @unlink($pdfPath);
        }

        if (! is_array($run)) {
            return;
        }

        $resultPath = trim((string) ($run['result_path'] ?? ''));
        if ($resultPath !== '') {
            try {
                Storage::disk($resultDisk)->delete($resultPath);
            } catch (\Throwable $e) {
                Log::warning('[TenthLine] TextractHealthCheckCommand: failed to delete health-check result artifact', [
                    'result_disk' => $resultDisk,
                    'result_path' => $resultPath,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $sourceKey = trim((string) ($run['source_key'] ?? ''));
        if ($sourceKey !== '' && ! (bool) ($run['source_deleted'] ?? false)) {
            try {
                Storage::disk($sourceDisk)->delete($sourceKey);
            } catch (\Throwable $e) {
                Log::warning('[TenthLine] TextractHealthCheckCommand: failed to delete health-check source artifact', [
                    'source_disk' => $sourceDisk,
                    'source_key' => $sourceKey,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}

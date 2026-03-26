<?php

namespace Tests\Feature;

use App\Services\Scanned\TextractJobCoordinator;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TextractHealthCheckCommandTest extends TestCase
{
    public function test_it_runs_the_textract_health_check_successfully(): void
    {
        Storage::fake('textract');
        Storage::fake('local');

        config([
            'textract.enabled' => true,
            'textract.source_disk' => 'textract',
            'textract.result_disk' => 'local',
            'textract.region' => 'us-east-1',
            'filesystems.disks.textract.bucket' => 'tenthlines3bucket',
        ]);

        $textractJobCoordinator = Mockery::mock(TextractJobCoordinator::class);
        $textractJobCoordinator->shouldReceive('enabled')->once()->andReturnTrue();
        $textractJobCoordinator->shouldReceive('resolvePageDimensions')
            ->once()
            ->with(Mockery::on(static fn (string $path): bool => is_file($path)))
            ->andReturn([
                1 => ['width' => 612.0, 'height' => 792.0],
            ]);
        $textractJobCoordinator->shouldReceive('runSynchronous')
            ->once()
            ->withArgs(static function (string $jobId, string $pdfPath, array $pageDimensions, array $options): bool {
                return str_starts_with($jobId, 'healthcheck-')
                    && is_file($pdfPath)
                    && (($pageDimensions[1]['width'] ?? null) === 612.0)
                    && (($options['prefix'] ?? null) === 'textract/healthcheck/input')
                    && (($options['result_prefix'] ?? null) === 'pdf-jobs/healthcheck');
            })
            ->andReturn([
                'status' => 'SUCCEEDED',
                'job_id' => 'healthcheck-job-123',
                'bucket' => 'tenthlines3bucket',
                'source_key' => 'textract/healthcheck/input/healthcheck-job-123/input.pdf',
                'result_path' => 'pdf-jobs/healthcheck/healthcheck-job-123/textract-pages.json',
                'started_at' => now()->subSecond()->toISOString(),
                'completed_at' => now()->toISOString(),
                'poll_attempts' => 1,
                'warnings' => [],
                'source_deleted' => true,
                'pages' => [
                    1 => [
                        'page_no' => 1,
                        'engine' => 'ocr',
                        'ocr_provider' => 'textract',
                        'page_width' => 612.0,
                        'page_height' => 792.0,
                        'page_rotation' => 0,
                        'raw_lines' => [
                            [
                                'id' => 'line-1',
                                'text' => 'Textract health check',
                                'x_start' => 72.0,
                                'x_end' => 260.0,
                                'y' => 680.0,
                                'top' => 690.0,
                                'bottom' => 670.0,
                                'height' => 20.0,
                            ],
                        ],
                    ],
                ],
            ]);

        $this->app->instance(TextractJobCoordinator::class, $textractJobCoordinator);

        $this->artisan('textract:health-check')
            ->assertExitCode(0);

        $this->assertSame([], Storage::disk('textract')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}

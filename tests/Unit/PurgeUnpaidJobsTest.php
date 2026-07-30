<?php

namespace Tests\Unit;

use App\Console\Commands\PurgeUnpaidJobsCommand;
use App\Enums\JobErrorCode;
use App\Models\PdfJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgeUnpaidJobsTest extends TestCase
{
    use RefreshDatabase;

    private function makeAwaitingJob(array $overrides = []): PdfJob
    {
        return PdfJob::create(array_merge([
            'id' => \Str::uuid()->toString(),
            'filename' => 'test.pdf',
            'status' => 'awaiting_payment',
            'total_pages' => 3,
            'processed_pages' => 3,
            'progress' => 100,
            'page_count' => 3,
            'payable_pages' => 3,
            'payment_deadline_at' => now()->subHour(),
            'line_interval' => 10,
            'margin' => 'right',
            'font_size_pt' => 8,
        ], $overrides));
    }

    public function test_expired_awaiting_payment_job_is_marked_failed(): void
    {
        Storage::fake('local');
        $job = $this->makeAwaitingJob();

        $this->artisan('pdf-jobs:purge-unpaid')->assertSuccessful();

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertSame(JobErrorCode::PaymentDeadlineExpired, $job->error_code);
        $this->assertNotNull($job->storage_deleted_at);
    }

    public function test_job_with_future_deadline_is_not_purged(): void
    {
        Storage::fake('local');
        $job = $this->makeAwaitingJob(['payment_deadline_at' => now()->addHours(24)]);

        $this->artisan('pdf-jobs:purge-unpaid')->assertSuccessful();

        $job->refresh();
        $this->assertSame('awaiting_payment', $job->status);
    }

    public function test_completed_job_is_not_affected(): void
    {
        Storage::fake('local');
        $job = $this->makeAwaitingJob([
            'status' => 'completed',
            'payment_deadline_at' => now()->subHour(),
        ]);

        $this->artisan('pdf-jobs:purge-unpaid')->assertSuccessful();

        $job->refresh();
        $this->assertSame('completed', $job->status);
    }
}

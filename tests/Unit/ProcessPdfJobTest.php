<?php

namespace Tests\Unit;

use App\Jobs\ProcessPdfJob;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProcessPdfJobTest extends TestCase
{
    public function test_it_normalizes_iso8601_ocr_timestamps_for_database_storage(): void
    {
        $job = new ProcessPdfJob('test-job-id');
        $method = new \ReflectionMethod($job, 'normalizeDatabaseTimestamp');
        $method->setAccessible(true);

        $normalized = $method->invoke($job, '2026-03-26T10:28:29.012249Z');

        $this->assertSame('2026-03-26 10:28:29', $normalized);
    }

    public function test_it_normalizes_datetime_objects_for_database_storage(): void
    {
        $job = new ProcessPdfJob('test-job-id');
        $method = new \ReflectionMethod($job, 'normalizeDatabaseTimestamp');
        $method->setAccessible(true);

        $normalized = $method->invoke($job, Carbon::parse('2026-03-26T10:29:42.199709Z'));

        $this->assertSame('2026-03-26 10:29:42', $normalized);
    }
}

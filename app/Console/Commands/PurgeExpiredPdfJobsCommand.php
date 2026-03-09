<?php

namespace App\Console\Commands;

use App\Models\PdfJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredPdfJobsCommand extends Command
{
    protected $signature = 'pdf-jobs:purge-expired {--hours=24 : Retention window in hours}';

    protected $description = 'Delete stored input/output PDF files after retention period and mark jobs as deleted';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = Carbon::now()->subHours($hours);

        $jobs = PdfJob::query()
            ->where('created_at', '<=', $cutoff)
            ->whereIn('status', ['completed', 'failed'])
            ->get();

        $deletedCount = 0;

        foreach ($jobs as $job) {
            $jobDir = "pdf-jobs/{$job->id}";
            Storage::disk('local')->deleteDirectory($jobDir);

            $job->forceFill([
                'status' => 'deleted',
                'output_path' => null,
                'storage_deleted_at' => now(),
                'error_message' => $job->error_message ?: "Files deleted automatically after {$hours} hours.",
            ])->save();

            $deletedCount++;
        }

        Log::info('[LegalLine] pdf-jobs.purge-expired.completed', [
            'hours' => $hours,
            'cutoff' => $cutoff->toIso8601String(),
            'deleted_jobs' => $deletedCount,
        ]);

        $this->info("Purged {$deletedCount} expired PDF job storage directories.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\JobErrorCode;
use App\Models\PdfJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeUnpaidJobsCommand extends Command
{
    protected $signature = 'pdf-jobs:purge-unpaid';

    protected $description = 'Delete output PDFs for awaiting_payment jobs that have passed their payment deadline';

    public function handle(): int
    {
        $jobs = PdfJob::query()
            ->where('status', 'awaiting_payment')
            ->where('payment_deadline_at', '<=', now())
            ->get();

        $purgedCount = 0;

        foreach ($jobs as $job) {
            $jobDir = "pdf-jobs/{$job->id}";
            Storage::disk('local')->deleteDirectory($jobDir);

            $job->forceFill([
                'status' => 'failed',
                'error_code' => JobErrorCode::PaymentDeadlineExpired->value,
                'error_message' => JobErrorCode::PaymentDeadlineExpired->userMessage(),
                'output_path' => null,
                'storage_deleted_at' => now(),
            ])->save();

            $purgedCount++;
        }

        Log::info('[TenthLine] pdf-jobs.purge-unpaid.completed', [
            'purged_jobs' => $purgedCount,
        ]);

        $this->info("Purged {$purgedCount} expired unpaid PDF job(s).");

        return self::SUCCESS;
    }
}

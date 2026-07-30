<?php

namespace App\Console\Commands;

use App\Models\SupportTicket;
use App\Settings\RetentionSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PurgeSupportAttachmentsCommand extends Command
{
    protected $signature = 'support:purge-attachments';

    protected $description = 'Delete support ticket attachments after the configured retention window post-resolution';

    public function handle(RetentionSettings $retentionSettings): int
    {
        $retentionHours = max(1, $retentionSettings->support_attachment_retention_hours);

        $tickets = SupportTicket::query()
            ->with('attachments')
            ->whereIn('status', ['resolved', 'closed'])
            ->whereNotNull('resolved_at')
            ->whereNull('attachments_deleted_at')
            ->where('resolved_at', '<=', now()->subHours($retentionHours))
            ->get();

        $ticketCount = 0;
        $fileCount = 0;

        foreach ($tickets as $ticket) {
            foreach ($ticket->attachments as $attachment) {
                if ($attachment->storage_path) {
                    Storage::disk('local')->delete($attachment->storage_path);
                }
                $attachment->delete(); // soft delete
                $fileCount++;
            }

            $ticket->forceFill(['attachments_deleted_at' => now()])->save();
            $ticketCount++;
        }

        Log::info('[TenthLine] support.purge-attachments.completed', [
            'retention_hours' => $retentionHours,
            'tickets_processed' => $ticketCount,
            'files_deleted' => $fileCount,
        ]);

        $this->info("Purged attachments for {$ticketCount} ticket(s) ({$fileCount} file(s)).");

        return self::SUCCESS;
    }
}

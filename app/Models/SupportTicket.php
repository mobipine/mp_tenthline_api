<?php

namespace App\Models;

use App\Enums\SupportTicketStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    use HasUuids;

    protected $table = 'support_tickets';

    protected $fillable = [
        'reference',
        'email',
        'subject',
        'description',
        'status',
        'user_id',
        'pdf_job_id',
        'admin_notes',
        'resolved_at',
        'attachments_deleted_at',
    ];

    protected $casts = [
        'status' => SupportTicketStatus::class,
        'resolved_at' => 'datetime',
        'attachments_deleted_at' => 'datetime',
    ];

    public static function generateReference(): string
    {
        return 'TKT-' . strtoupper(Str::random(8));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pdfJob(): BelongsTo
    {
        return $this->belongsTo(PdfJob::class, 'pdf_job_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class);
    }

    public function markResolved(): void
    {
        $this->forceFill([
            'status' => SupportTicketStatus::Resolved,
            'resolved_at' => now(),
        ])->save();
    }

    public function attachmentsEligibleForPurge(int $retentionHours): bool
    {
        return $this->status->isTerminal()
            && $this->resolved_at !== null
            && $this->resolved_at->addHours($retentionHours)->isPast()
            && $this->attachments_deleted_at === null;
    }
}

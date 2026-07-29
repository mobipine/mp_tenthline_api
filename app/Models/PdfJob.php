<?php

namespace App\Models;

use App\Enums\JobErrorCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PdfJob extends Model
{
    use HasUuids;

    protected $table = 'pdf_jobs';

    protected $fillable = [
        'filename',
        'status',
        'user_id',
        'total_pages',
        'processed_pages',
        'progress',
        'eta_seconds',
        'error_message',
        'output_path',
        'payment_id',
        'line_interval',
        'page_count',
        'margin',
        'font_size_pt',
        'ocr_provider',
        'ocr_status',
        'ocr_job_id',
        'ocr_started_at',
        'ocr_completed_at',
        'ocr_result_path',
        'ocr_error_message',
        'ocr_diagnostics',
        'storage_deleted_at',
        'error_code',
        'processing_report_id',
        'payable_pages',
        'payment_deadline_at',
    ];

    protected $casts = [
        'total_pages' => 'integer',
        'processed_pages' => 'integer',
        'progress' => 'integer',
        'eta_seconds' => 'integer',
        'line_interval' => 'integer',
        'page_count' => 'integer',
        'font_size_pt' => 'integer',
        'ocr_started_at' => 'datetime',
        'ocr_completed_at' => 'datetime',
        'ocr_diagnostics' => 'array',
        'storage_deleted_at' => 'datetime',
        'error_code' => JobErrorCode::class,
        'payable_pages' => 'integer',
        'payment_deadline_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processingReport(): HasOne
    {
        return $this->hasOne(ProcessingReport::class, 'pdf_job_id');
    }

    public function supportTickets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupportTicket::class, 'pdf_job_id');
    }

    public function isAwaitingPayment(): bool
    {
        return $this->status === 'awaiting_payment';
    }

    public function isPaymentDeadlineExpired(): bool
    {
        return $this->payment_deadline_at !== null && $this->payment_deadline_at->isPast();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'storage_deleted_at',
    ];

    protected $casts = [
        'total_pages' => 'integer',
        'processed_pages' => 'integer',
        'progress' => 'integer',
        'eta_seconds' => 'integer',
        'line_interval' => 'integer',
        'page_count' => 'integer',
        'font_size_pt' => 'integer',
        'storage_deleted_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

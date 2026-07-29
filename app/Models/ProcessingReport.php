<?php

namespace App\Models;

use App\Enums\PageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcessingReport extends Model
{
    use HasUuids;

    protected $table = 'processing_reports';

    protected $fillable = [
        'pdf_job_id',
        'uploaded_pages',
        'successful_pages',
        'low_confidence_pages',
        'failed_pages',
        'payable_pages',
        'unit_price',
        'total_amount',
        'currency',
        'bill_low_confidence_pages',
        'threshold_min_confidence',
        'threshold_min_text_boxes',
        'threshold_min_chars',
        'threshold_min_page_coverage',
        'diagnostics',
    ];

    protected $casts = [
        'uploaded_pages' => 'integer',
        'successful_pages' => 'integer',
        'low_confidence_pages' => 'integer',
        'failed_pages' => 'integer',
        'payable_pages' => 'integer',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'bill_low_confidence_pages' => 'boolean',
        'threshold_min_confidence' => 'float',
        'threshold_min_text_boxes' => 'integer',
        'threshold_min_chars' => 'integer',
        'threshold_min_page_coverage' => 'float',
        'diagnostics' => 'array',
    ];

    public function pdfJob(): BelongsTo
    {
        return $this->belongsTo(PdfJob::class, 'pdf_job_id');
    }

    public function pageResults(): HasMany
    {
        return $this->hasMany(PageProcessingResult::class)->orderBy('page_number');
    }

    public function hasZeroPayablePages(): bool
    {
        return $this->payable_pages === 0;
    }

    public function pageResultsByStatus(PageStatus $status): HasMany
    {
        return $this->hasMany(PageProcessingResult::class)
            ->where('status', $status->value)
            ->orderBy('page_number');
    }
}

<?php

namespace App\Models;

use App\Enums\PageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageProcessingResult extends Model
{
    protected $table = 'page_processing_results';

    protected $fillable = [
        'processing_report_id',
        'page_number',
        'status',
        'ocr_confidence',
        'text_box_count',
        'extracted_chars',
        'page_coverage_pct',
        'placement_mode',
        'line_labels_applied',
        'is_billable',
        'notes',
        'raw_diagnostics',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'status' => PageStatus::class,
        'ocr_confidence' => 'float',
        'text_box_count' => 'integer',
        'extracted_chars' => 'integer',
        'page_coverage_pct' => 'float',
        'line_labels_applied' => 'integer',
        'is_billable' => 'boolean',
        'raw_diagnostics' => 'array',
    ];

    public function processingReport(): BelongsTo
    {
        return $this->belongsTo(ProcessingReport::class);
    }
}

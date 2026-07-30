<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class OcrQualitySettings extends Settings
{
    /** Minimum average OCR confidence to classify a page as success (0.0–1.0) */
    public float $min_ocr_confidence;

    /** Minimum number of detected text boxes for success classification */
    public int $min_text_boxes;

    /** Minimum total extracted characters for success classification */
    public int $min_extracted_chars;

    /** Minimum page coverage percentage (0.0–1.0) for success classification */
    public float $min_page_coverage_pct;

    /** Whether low-confidence pages are included in the billable count */
    public bool $bill_low_confidence_pages;

    public static function group(): string
    {
        return 'ocr_quality';
    }
}

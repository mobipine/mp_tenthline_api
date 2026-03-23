<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Extraction engine
    |--------------------------------------------------------------------------
    |
    | auto    => try Poppler (pdftotext -bbox-layout) then fallback to smalot
    | poppler => prefer Poppler and fallback to smalot if extraction fails
    | smalot  => use Smalot parser only
    |
    */
    'extractor_engine' => env('PDF_LINE_EXTRACTOR_ENGINE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Smalot extraction tuning
    |--------------------------------------------------------------------------
    */
    'y_tolerance_pt' => (float) env('PDF_LINE_Y_TOLERANCE_PT', 3),

    /*
    |--------------------------------------------------------------------------
    | Poppler extraction tuning
    |--------------------------------------------------------------------------
    */
    'poppler_binary' => env('PDF_LINE_POPPLER_BINARY', 'pdftotext'),
    'qpdf_binary' => env('PDF_LINE_QPDF_BINARY', 'qpdf'),
    'pdftoppm_binary' => env('PDF_LINE_PDFTOPPM_BINARY', 'pdftoppm'),
    'tesseract_binary' => env('PDF_LINE_TESSERACT_BINARY', 'tesseract'),
    'poppler_baseline_ratio' => (float) env('PDF_LINE_POPPLER_BASELINE_RATIO', 0.78),
    'ocr_baseline_ratio' => (float) env('PDF_LINE_OCR_BASELINE_RATIO', 0.82),

    /*
    |--------------------------------------------------------------------------
    | Layout understanding and confidence
    |--------------------------------------------------------------------------
    */
    'header_region_ratio' => (float) env('PDF_LINE_HEADER_REGION_RATIO', 0.12),
    'footer_region_ratio' => (float) env('PDF_LINE_FOOTER_REGION_RATIO', 0.12),
    'header_footer_repeat_threshold' => (float) env('PDF_LINE_HEADER_FOOTER_REPEAT_THRESHOLD', 0.35),
    'header_footer_position_tolerance_pt' => (float) env('PDF_LINE_HEADER_FOOTER_POSITION_TOLERANCE_PT', 18.0),
    'body_region_top_padding_pt' => (float) env('PDF_LINE_BODY_REGION_TOP_PADDING_PT', 10.0),
    'body_region_bottom_padding_pt' => (float) env('PDF_LINE_BODY_REGION_BOTTOM_PADDING_PT', 12.0),
    'body_region_side_padding_pt' => (float) env('PDF_LINE_BODY_REGION_SIDE_PADDING_PT', 10.0),
    'minimum_line_confidence' => (float) env('PDF_LINE_MINIMUM_CONFIDENCE', 0.48),
    'minimum_page_confidence' => (float) env('PDF_LINE_MINIMUM_PAGE_CONFIDENCE', 0.58),
    'skip_low_confidence_pages' => (bool) env('PDF_LINE_SKIP_LOW_CONFIDENCE_PAGES', false),
    'low_confidence_page_strategy' => env('PDF_LINE_LOW_CONFIDENCE_PAGE_STRATEGY', 'skip'),
    'enable_ocr_fallback' => (bool) env('PDF_LINE_ENABLE_OCR_FALLBACK', false),
    'ocr_trigger_page_confidence' => (float) env('PDF_LINE_OCR_TRIGGER_PAGE_CONFIDENCE', 0.4),
    'enable_ocr_grid_reconstruction' => (bool) env('PDF_LINE_ENABLE_OCR_GRID_RECONSTRUCTION', true),
    'ocr_grid_min_trusted_lines' => (int) env('PDF_LINE_OCR_GRID_MIN_TRUSTED_LINES', 6),
    'ocr_grid_min_spacing_pt' => (float) env('PDF_LINE_OCR_GRID_MIN_SPACING_PT', 10.0),
    'ocr_grid_max_spacing_pt' => (float) env('PDF_LINE_OCR_GRID_MAX_SPACING_PT', 36.0),
    'ocr_grid_min_regular_support' => (float) env('PDF_LINE_OCR_GRID_MIN_REGULAR_SUPPORT', 0.6),
    'ocr_grid_snap_tolerance_factor' => (float) env('PDF_LINE_OCR_GRID_SNAP_TOLERANCE_FACTOR', 0.38),
    'ocr_grid_max_expansion_factor' => (float) env('PDF_LINE_OCR_GRID_MAX_EXPANSION_FACTOR', 2.4),

    /*
    |--------------------------------------------------------------------------
    | Label placement tuning
    |--------------------------------------------------------------------------
    */
    'line_number_inset_pt' => (float) env('PDF_LINE_NUMBER_INSET_PT', 3),
    'page_edge_padding_pt' => (float) env('PDF_LINE_PAGE_EDGE_PADDING_PT', 6),
    'label_width_factor' => (float) env('PDF_LINE_LABEL_WIDTH_FACTOR', 0.56),

    /*
    |--------------------------------------------------------------------------
    | Diagnostics
    |--------------------------------------------------------------------------
    */
    'enable_diagnostics' => (bool) env('PDF_LINE_ENABLE_DIAGNOSTICS', true),
    'debug_overlay' => (bool) env('PDF_LINE_DEBUG_OVERLAY', false),
];

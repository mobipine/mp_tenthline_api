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
    'poppler_baseline_ratio' => (float) env('PDF_LINE_POPPLER_BASELINE_RATIO', 0.78),

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

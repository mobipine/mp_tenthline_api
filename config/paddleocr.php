<?php

return [
    'enabled'          => (bool)  env('PADDLEOCR_ENABLED', false),
    'url'              => env('PADDLEOCR_URL', 'http://127.0.0.1:8765'),
    // Optional comma-separated list of sidecar instances (see run.sh). Pages
    // are round-robined across them so multi-page documents OCR in parallel.
    // Prefer several single-worker instances over `uvicorn --workers`: Paddle
    // crashes in forked worker processes on macOS.
    'urls'             => env('PADDLEOCR_URLS', ''),
    'timeout_seconds'  => (int)   env('PADDLEOCR_TIMEOUT_SECONDS', 180),
    'min_confidence'   => (float) env('PADDLEOCR_MIN_CONFIDENCE', 0.0),
    // Fraction of the OCR box height (measured up from the box bottom) where
    // the text baseline sits. Labels are drawn with their font baseline at this
    // y, so ~0.2 (descender space) puts the -N mark visually ON its line;
    // higher values float the label toward the line above.
    'baseline_ratio'   => (float) env('PADDLEOCR_BASELINE_RATIO', 0.20),
    'render_dpi'       => (int)   env('PADDLEOCR_RENDER_DPI', 300),
    // How many pages to OCR concurrently. Match this to the sidecar's worker
    // count (uvicorn --workers N); extra concurrency beyond that just queues.
    'concurrency'      => (int)   env('PADDLEOCR_CONCURRENCY', 2),

    /*
    |--------------------------------------------------------------------------
    | Row clustering (numbering profile)
    |--------------------------------------------------------------------------
    |
    | PaddleOCR returns one bbox per detected text fragment, and a single
    | visual line frequently produces several fragments (an indented clause
    | number + its text, widely spaced words, a date on a signature line).
    | Because tenthlining counts text lines as an ordinal running total, each
    | extra fragment shifts every number below it. Row clustering groups
    | fragments that share a visual row into a single numbered line so that
    | "one visual row = one line", matching how a human counts.
    |
    | These knobs are the deterministic "custom instructions" layer: tune them
    | per document profile without touching code.
    |
    */
    'row_cluster_enabled'        => (bool)  env('PADDLEOCR_ROW_CLUSTER_ENABLED', true),
    // Fraction of the smaller box height that two boxes must overlap vertically
    // to be treated as the same row.
    'row_cluster_overlap_ratio'  => (float) env('PADDLEOCR_ROW_CLUSTER_OVERLAP_RATIO', 0.35),
    // If vertical overlap is inconclusive, boxes whose baselines are within
    // this multiple of the median line height are still merged.
    'row_cluster_baseline_factor'=> (float) env('PADDLEOCR_ROW_CLUSTER_BASELINE_FACTOR', 0.5),
    // A box taller than this multiple of the median line height is assumed to
    // contain multiple stacked rows and is split evenly into that many lines.
    'row_split_height_factor'    => (float) env('PADDLEOCR_ROW_SPLIT_HEIGHT_FACTOR', 1.7),
];

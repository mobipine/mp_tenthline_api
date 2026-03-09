# LegalLine PDF Line Numbering: Processing Flow, Current Behavior, and Improvements

## 1) End-to-end flow

1. Frontend uploads a PDF and configuration (`margin`, `font_size_pt`, `payment_reference`) to `POST /api/upload`.
2. Backend validates payment and file, creates a `pdf_jobs` row, stores input as `storage/app/private/pdf-jobs/{jobId}/input.pdf`.
3. Backend dispatches `ProcessPdfJob` (sync when `enable_payment=false`, async when `enable_payment=true`).
4. `ProcessPdfJob` updates progress and broadcasts websocket updates (`PdfJobUpdated`) during processing.
5. `PdfLineNumberService` reads page text geometry via `PdfLineExtractor`, applies labels on every 10th line, writes `output.pdf`.
6. Job is marked completed, and download endpoint serves `/api/job/{id}/download`.

## 2) Current line-numbering algorithm

### 2.1 Extraction (`PdfLineExtractor`)

- Uses `smalot/pdfparser` `getDataTm()` to get text matrix coordinates per text segment.
- Includes font size in extracted data for better width estimation.
- Groups segments into logical lines by Y proximity (`LINE_Y_TOLERANCE_PT = 3`).
- Builds per-line anchors:
  - `y` (baseline-like representative Y)
  - `x_start` (leftmost segment start)
  - `x_end` (rightmost estimated segment end)

### 2.2 Placement (`PdfLineNumberService`)

- Uses FPDI in points (`pt`) to match extractor coordinates.
- For every 10th line (10, 20, 30...), computes label `-10`, `-20`, etc.
- Converts Y from PDF bottom-origin to FPDI top-origin.
- Draws label with `Text(x, y, label)` so Y acts as a text baseline (more accurate than using `Cell`).
- X placement:
  - `margin=right`: places label just after detected `x_end`.
  - `margin=left`: places label just before detected `x_start`.
  - Both are clamped to page edges.
- If no text anchors can be extracted for a page (image-only scans, etc.), falls back to fixed grid numbering.

## 3) What was changed now

1. **Fixed 10-line interval**
   - Frontend line-interval control removed.
   - Backend enforces interval `10` regardless of client payload.
2. **Improved placement fidelity**
   - Switched from `Cell()` to `Text()` for baseline-aligned Y drawing.
   - Added per-line `x_start/x_end` anchor usage to place labels next to actual line endpoints.

## 4) Why numbering can still be slightly off in some PDFs

Even with anchor-based placement, exactness can vary due to:

1. `smalot/pdfparser` does not provide exact rendered glyph bounding boxes by default.
2. `x_end` is estimated from text length and font size (good approximation, not perfect kerning-aware width).
3. Complex PDFs (rotated text, transformed text matrices, mixed writing modes, ligatures) reduce precision.
4. Scanned PDFs with OCR layers can have inconsistent text geometry.

## 5) Recommended improvements (priority order)

### P1 (highest impact)

1. **Use exact glyph/word bounding boxes from a rendering-aware engine**
   - Preferred: PDFium/Poppler-based extraction pipeline.
   - Outcome: true line-end coordinates, much more precise right-edge numbering.

2. **Font-aware width estimation fallback**
   - If full bounding boxes are unavailable, map fonts to width tables for better `x_end` estimation.

### P2

3. **Adaptive per-page calibration**
   - Detect dominant text block region and baseline spacing per page.
   - Smooth noisy extracted anchors and reject outliers.

4. **Rotation/transform handling**
   - Normalize coordinates for rotated text blocks before grouping lines.

### P3

5. **Quality diagnostics and debug artifact mode**
   - Generate optional overlay output showing anchor points and label positions.
   - Store metrics in logs (mean line spacing, anchor confidence, fallback usage rate).

## 6) Operational tuning knobs

- `PdfLineExtractor::LINE_Y_TOLERANCE_PT`
- `PdfLineNumberService::LINE_NUMBER_INSET_PT`
- `PdfLineNumberService::PAGE_EDGE_PADDING_PT`
- `PdfLineNumberService::LABEL_WIDTH_FACTOR`

These constants can be tuned per document corpus for better practical alignment.

## 7) Validation checklist for this feature

1. Test text-heavy PDFs with known line endings.
2. Test mixed fonts and font sizes.
3. Test narrow and wide page sizes.
4. Test scanned PDFs (expect fallback behavior where extraction fails).
5. Compare right-margin labels visually against 10th/20th line endpoints on at least 20 sample documents.

## 8) Implemented improvements (March 9, 2026)

The recommended improvements have now been implemented in code:

1. **Rendering-aware extraction path (P1)**
   - Added Poppler-based extraction using `pdftotext -bbox-layout` with true line bounding boxes.
   - Engine selection is configurable:
     - `PDF_LINE_EXTRACTOR_ENGINE=auto|poppler|smalot`
   - In `auto`, backend tries Poppler first and falls back to Smalot if needed.

2. **Font-aware width fallback (P1)**
   - Smalot fallback now uses character-category width estimation instead of a single global factor.
   - Supports improved handling for monospace-like fonts and mixed character widths.

3. **Adaptive per-page calibration (P2)**
   - Smalot grouping now computes adaptive Y tolerance from observed line spacing.
   - Outlier lines are filtered with a width-based pass to reduce noisy anchors.

4. **Rotation/transform handling (P2)**
   - Smalot extraction now evaluates text matrix angle.
   - Non-horizontal rotated segments are skipped to avoid polluting line anchors.
   - Diagnostics report how many rotated segments were ignored.

5. **Diagnostics and debug artifact mode (P3)**
   - Detailed extractor and placement diagnostics are logged into `laravel.log`.
   - Optional debug overlay mode draws anchor lines and label marks into output PDFs:
     - `PDF_LINE_DEBUG_OVERLAY=true`
   - Additional tuning/env knobs were added:
     - `PDF_LINE_POPPLER_BINARY`
     - `PDF_LINE_POPPLER_BASELINE_RATIO`
     - `PDF_LINE_Y_TOLERANCE_PT`
     - `PDF_LINE_NUMBER_INSET_PT`
     - `PDF_LINE_PAGE_EDGE_PADDING_PT`
     - `PDF_LINE_LABEL_WIDTH_FACTOR`
     - `PDF_LINE_ENABLE_DIAGNOSTICS`

## 9) Test status

Automated backend tests were added and passed:

1. `Tests\Unit\PdfLineExtractorTest`
   - Verifies Poppler engine extracts ordered line anchors.
2. `Tests\Feature\PdfLineNumberServiceTest`
   - Generates a sample PDF, runs numbering, verifies output contains `-10` and `-20`.

Current suite result: **4 passed, 0 failed**.

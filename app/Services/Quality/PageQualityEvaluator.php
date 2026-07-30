<?php

namespace App\Services\Quality;

use App\Enums\PageStatus;
use App\Settings\OcrQualitySettings;

class PageQualityEvaluator
{
    // Placement modes that always indicate the page was explicitly skipped
    private const SKIP_MODES = [
        'skip_low_confidence',
        'skip_table_page',
        'skip_scanned_non_body',
    ];

    // Placement mode that means no line anchors were found at all
    private const NO_ANCHOR_MODE = 'fallback_grid';

    // Placement mode for low-confidence pages where a fixed grid was applied
    private const LOW_CONFIDENCE_GRID_MODE = 'fallback_grid_low_confidence';

    public function __construct(
        private readonly OcrQualitySettings $settings,
    ) {}

    /**
     * Evaluate all pages from a completed PdfLineNumberService run.
     *
     * @param  array<int, array<string, mixed>>  $runPages  from getLastRunDiagnostics()['pages']
     * @return list<PageQualityResult>
     */
    public function evaluateAll(array $runPages): array
    {
        $results = [];

        foreach ($runPages as $pageNo => $pageData) {
            $results[] = $this->evaluatePage((int) $pageNo, $pageData);
        }

        return $results;
    }

    /**
     * Evaluate a single page from the run diagnostics.
     *
     * @param  array<string, mixed>  $pageRunData
     */
    public function evaluatePage(int $pageNo, array $pageRunData): PageQualityResult
    {
        $placementMode = (string) ($pageRunData['placement_mode'] ?? 'unknown');
        $labelsDrawn = (int) ($pageRunData['labels_drawn'] ?? 0);
        $diagnostics = is_array($pageRunData['diagnostics'] ?? null) ? $pageRunData['diagnostics'] : [];

        [$avgConfidence, $textBoxCount, $extractedChars, $pageCoveragePct] =
            $this->extractMetrics($pageRunData, $diagnostics);

        // Explicit skips are always failed — no number were applied, page is not usable
        if (in_array($placementMode, self::SKIP_MODES, true)) {
            return new PageQualityResult(
                pageNumber: $pageNo,
                status: PageStatus::Failed,
                ocrConfidence: $avgConfidence,
                textBoxCount: $textBoxCount,
                extractedChars: $extractedChars,
                pageCoveragePct: $pageCoveragePct,
                placementMode: $placementMode,
                lineLabelsApplied: 0,
                isBillable: false,
                notes: "Page explicitly skipped by processing engine ({$placementMode}).",
                rawDiagnostics: $diagnostics ?: null,
            );
        }

        // Fallback grid with no anchors: page had no detectable text structure
        if ($placementMode === self::NO_ANCHOR_MODE) {
            return new PageQualityResult(
                pageNumber: $pageNo,
                status: PageStatus::Failed,
                ocrConfidence: $avgConfidence,
                textBoxCount: $textBoxCount,
                extractedChars: $extractedChars,
                pageCoveragePct: $pageCoveragePct,
                placementMode: $placementMode,
                lineLabelsApplied: $labelsDrawn,
                isBillable: false,
                notes: 'No line anchors detected. Page may contain only images or unreadable content.',
                rawDiagnostics: $diagnostics ?: null,
            );
        }

        // Fixed grid applied to low-confidence page
        if ($placementMode === self::LOW_CONFIDENCE_GRID_MODE) {
            return new PageQualityResult(
                pageNumber: $pageNo,
                status: PageStatus::LowConfidence,
                ocrConfidence: $avgConfidence,
                textBoxCount: $textBoxCount,
                extractedChars: $extractedChars,
                pageCoveragePct: $pageCoveragePct,
                placementMode: $placementMode,
                lineLabelsApplied: $labelsDrawn,
                isBillable: $this->settings->bill_low_confidence_pages,
                notes: 'Low confidence: fixed-grid line numbers applied.',
                rawDiagnostics: $diagnostics ?: null,
            );
        }

        // Trusted mode: line anchors were applied. Now check quality thresholds.
        $engine = (string) ($diagnostics['engine'] ?? 'unknown');
        $isOcrPage = $engine === 'ocr';

        if ($isOcrPage) {
            return $this->classifyOcrPage(
                $pageNo, $avgConfidence, $textBoxCount, $extractedChars,
                $pageCoveragePct, $placementMode, $labelsDrawn, $diagnostics,
            );
        }

        // Text-extracted page (poppler / smalot): use page confidence as the primary signal.
        // These pages are almost always processable; we only flag them low-confidence
        // if the extractor itself reported low confidence.
        return $this->classifyTextPage(
            $pageNo, $avgConfidence, $textBoxCount, $extractedChars,
            $pageCoveragePct, $placementMode, $labelsDrawn, $pageRunData, $diagnostics,
        );
    }

    /**
     * Classify a page processed via OCR, applying all quality thresholds.
     *
     * @param  array<string, mixed>  $diagnostics
     */
    private function classifyOcrPage(
        int $pageNo,
        float $avgConfidence,
        int $textBoxCount,
        int $extractedChars,
        float $pageCoveragePct,
        string $placementMode,
        int $labelsDrawn,
        array $diagnostics,
    ): PageQualityResult {
        $pageWidth = (float) ($diagnostics['page_width'] ?? 0.0);
        $pageHeight = (float) ($diagnostics['page_height'] ?? 0.0);
        $hasDimensions = $pageWidth > 0 && $pageHeight > 0;

        $meetsConfidence = $avgConfidence >= $this->settings->min_ocr_confidence;
        $meetsTextBoxes = $textBoxCount >= $this->settings->min_text_boxes;
        $meetsChars = $extractedChars >= $this->settings->min_extracted_chars;
        $meetsCoverage = ! $hasDimensions || $pageCoveragePct >= $this->settings->min_page_coverage_pct;

        if ($meetsConfidence && $meetsTextBoxes && $meetsChars && $meetsCoverage) {
            return new PageQualityResult(
                pageNumber: $pageNo,
                status: PageStatus::Success,
                ocrConfidence: $avgConfidence,
                textBoxCount: $textBoxCount,
                extractedChars: $extractedChars,
                pageCoveragePct: $pageCoveragePct,
                placementMode: $placementMode,
                lineLabelsApplied: $labelsDrawn,
                isBillable: true,
                rawDiagnostics: $diagnostics ?: null,
            );
        }

        // Partial content — some text was detected, but thresholds not fully met
        $hasAnyContent = $textBoxCount >= 1 && $extractedChars >= 1;
        if ($hasAnyContent) {
            $reasons = [];
            if (! $meetsConfidence) {
                $reasons[] = sprintf('confidence %.2f < %.2f', $avgConfidence, $this->settings->min_ocr_confidence);
            }
            if (! $meetsTextBoxes) {
                $reasons[] = sprintf('%d text boxes < %d required', $textBoxCount, $this->settings->min_text_boxes);
            }
            if (! $meetsChars) {
                $reasons[] = sprintf('%d chars < %d required', $extractedChars, $this->settings->min_extracted_chars);
            }
            if (! $meetsCoverage) {
                $reasons[] = sprintf('coverage %.1f%% < %.1f%% required', $pageCoveragePct * 100, $this->settings->min_page_coverage_pct * 100);
            }

            return new PageQualityResult(
                pageNumber: $pageNo,
                status: PageStatus::LowConfidence,
                ocrConfidence: $avgConfidence,
                textBoxCount: $textBoxCount,
                extractedChars: $extractedChars,
                pageCoveragePct: $pageCoveragePct,
                placementMode: $placementMode,
                lineLabelsApplied: $labelsDrawn,
                isBillable: $this->settings->bill_low_confidence_pages,
                notes: 'Partially meets quality thresholds: ' . implode('; ', $reasons) . '.',
                rawDiagnostics: $diagnostics ?: null,
            );
        }

        return new PageQualityResult(
            pageNumber: $pageNo,
            status: PageStatus::Failed,
            ocrConfidence: $avgConfidence,
            textBoxCount: $textBoxCount,
            extractedChars: $extractedChars,
            pageCoveragePct: $pageCoveragePct,
            placementMode: $placementMode,
            lineLabelsApplied: $labelsDrawn,
            isBillable: false,
            notes: 'OCR returned no usable text for this page.',
            rawDiagnostics: $diagnostics ?: null,
        );
    }

    /**
     * Classify a text-extracted page (poppler / smalot / non-OCR engine).
     *
     * @param  array<string, mixed>  $pageRunData
     */
    private function classifyTextPage(
        int $pageNo,
        float $avgConfidence,
        int $textBoxCount,
        int $extractedChars,
        float $pageCoveragePct,
        string $placementMode,
        int $labelsDrawn,
        array $pageRunData,
        array $diagnostics = [],
    ): PageQualityResult {
        $pageConfidenceLabel = (string) ($pageRunData['diagnostics']['page_confidence_label'] ?? 'high');
        $lowConfidence = (bool) ($pageRunData['low_confidence'] ?? false);

        // Text-extracted pages only get low_confidence if the scorer explicitly flagged them
        if (! $lowConfidence && $pageConfidenceLabel !== 'low') {
            return new PageQualityResult(
                pageNumber: $pageNo,
                status: PageStatus::Success,
                ocrConfidence: $avgConfidence,
                textBoxCount: $textBoxCount,
                extractedChars: $extractedChars,
                pageCoveragePct: $pageCoveragePct,
                placementMode: $placementMode,
                lineLabelsApplied: $labelsDrawn,
                isBillable: true,
                rawDiagnostics: $diagnostics ?: null,
            );
        }

        return new PageQualityResult(
            pageNumber: $pageNo,
            status: PageStatus::LowConfidence,
            ocrConfidence: $avgConfidence,
            textBoxCount: $textBoxCount,
            extractedChars: $extractedChars,
            pageCoveragePct: $pageCoveragePct,
            placementMode: $placementMode,
            lineLabelsApplied: $labelsDrawn,
            isBillable: $this->settings->bill_low_confidence_pages,
            notes: 'Text extraction returned low-confidence results for this page.',
            rawDiagnostics: $diagnostics ?: null,
        );
    }

    /**
     * Extract quality metrics from run-page data and its embedded diagnostics.
     *
     * @param  array<string, mixed>  $pageRunData
     * @param  array<string, mixed>  $diagnostics
     * @return array{float, int, int, float}  [avgConfidence, textBoxCount, extractedChars, pageCoveragePct]
     */
    private function extractMetrics(array $pageRunData, array $diagnostics): array
    {
        $scoredLines = is_array($diagnostics['scored_lines'] ?? null) ? $diagnostics['scored_lines'] : [];
        $pageWidth = (float) ($diagnostics['page_width'] ?? 0.0);
        $pageHeight = (float) ($diagnostics['page_height'] ?? 0.0);
        $pageArea = $pageWidth * $pageHeight;

        $textBoxCount = count($scoredLines);
        $extractedChars = 0;
        $confidenceSum = 0.0;
        $coverageArea = 0.0;

        foreach ($scoredLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $charCount = (int) ($line['char_count'] ?? mb_strlen((string) ($line['text'] ?? '')));
            $extractedChars += $charCount;
            $confidenceSum += (float) ($line['confidence'] ?? 0.0);

            // Approximate line coverage as a rectangle
            $lineWidth = max(0.0, (float) ($line['x_end'] ?? 0.0) - (float) ($line['x_start'] ?? 0.0));
            $lineHeight = max(0.0, (float) ($line['height'] ?? 10.0));
            $coverageArea += $lineWidth * $lineHeight;
        }

        $avgConfidence = $textBoxCount > 0
            ? $confidenceSum / $textBoxCount
            : (float) ($pageRunData['page_confidence'] ?? 0.0);

        $pageCoveragePct = $pageArea > 0 ? min(1.0, $coverageArea / $pageArea) : 0.0;

        return [$avgConfidence, $textBoxCount, $extractedChars, $pageCoveragePct];
    }
}

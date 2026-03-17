<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;

class PdfLineNumberService
{
    /** Additional inset from selected page margin (points). */
    private const LINE_NUMBER_INSET_PT = 3;

    /** Keep labels away from absolute page edge (points). */
    private const PAGE_EDGE_PADDING_PT = 6;

    /** Approximate width per label character in points relative to font size. */
    private const LABEL_WIDTH_FACTOR = 0.56;

    /** Fallback fixed grid: top margin (points). */
    private const TOP_MARGIN_PT = 72;

    /** Fallback fixed grid: bottom margin (points). */
    private const BOTTOM_MARGIN_PT = 72;

    /** Fallback fixed grid: line height (points). */
    private const LINE_HEIGHT_PT = 24;

    public function __construct(
        private readonly PdfLineExtractor $lineExtractor,
        private readonly PdfFpdiCompatibilityService $fpdiCompatibility
    ) {}

    /**
     * Add line numbers to a PDF. Numbers are placed on every 10th (20th, 30th…) actual text line:
     * we detect where each line ends, then place the number at that line's Y (snaps to the line).
     * Numbering resets per page. If line detection fails for a page, falls back to fixed grid.
     *
     * @param  string  $inputPath  Full path to input PDF
     * @param  string  $outputPath  Full path for output PDF
     * @param  callable  $onPageProcessed  Called after each page (pageNumber, totalPages) for progress
     */
    public function addLineNumbers(
        string $inputPath,
        string $outputPath,
        int $lineInterval = 10,
        string $margin = 'left',
        int $fontSizePt = 8,
        ?callable $onPageProcessed = null
    ): int {
        $lineInsetPt = $this->lineNumberInsetPt();
        $pageEdgePaddingPt = $this->pageEdgePaddingPt();
        $labelWidthFactor = $this->labelWidthFactor();
        $drawDebugOverlay = (bool) config('line_numbering.debug_overlay', false);
        $diagnosticsEnabled = (bool) config('line_numbering.enable_diagnostics', true);

        Log::info('[LegalLine] PdfLineNumberService: line numbering started', [
            'input_path' => $inputPath,
            'output_path' => $outputPath,
            'line_interval' => $lineInterval,
            'margin' => $margin,
            'font_size_pt' => $fontSizePt,
            'line_number_inset_pt' => $lineInsetPt,
            'page_edge_padding_pt' => $pageEdgePaddingPt,
            'label_width_factor' => $labelWidthFactor,
            'debug_overlay' => $drawDebugOverlay,
        ]);

        $compatibleSource = $this->fpdiCompatibility->resolveProcessablePath($inputPath);
        if (! $compatibleSource['processable']) {
            throw new \RuntimeException($compatibleSource['message'] ?? $this->fpdiCompatibility->unsupportedMessage());
        }

        $effectiveInputPath = $compatibleSource['path'];

        try {
            $lineAnchorsPerPage = $this->lineExtractor->getLineAnchorsPerPage($effectiveInputPath);
            $extractorDiagnostics = $this->lineExtractor->getLastDiagnostics();

            // Use points so coordinates match (extractor and FPDI both in pt)
            $pdf = new Fpdi('P', 'pt');
            $pageCount = $pdf->setSourceFile($effectiveInputPath);
            Log::debug('[LegalLine] PdfLineNumberService: source opened', [
                'page_count' => $pageCount,
                'normalized_input' => $compatibleSource['normalized'],
            ]);

            $fallbackPages = 0;
            $totalLabelsDrawn = 0;

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                $pdf->AddPage($size['orientation'] ?? 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);

                $pdf->SetFont('Helvetica', '', $fontSizePt);
                $pdf->SetTextColor(80, 80, 80);

                $pageWidth = $size['width'];
                $pageHeight = $size['height'];

                $lineAnchors = $lineAnchorsPerPage[$pageNo] ?? [];
                $labelsDrawn = 0;

                if ($lineAnchors !== []) {
                    // Real lines: place number at every 10th, 20th, 30th… detected line anchor.
                    foreach ($this->everyNthLineWithLabel($lineAnchors, $lineInterval) as $lineNumber => $lineAnchor) {
                        $displayLabel = '-' . $lineNumber;
                        $yFromTop = $pageHeight - $lineAnchor['y']; // FPDI top-left coords, baseline-preserving via Text()
                        $x = $this->resolveLabelX(
                            $displayLabel,
                            $fontSizePt,
                            $margin,
                            $pageWidth,
                            $lineInsetPt,
                            $pageEdgePaddingPt,
                            $labelWidthFactor
                        );

                        $pdf->Text($x, $yFromTop, $displayLabel);

                        if ($drawDebugOverlay) {
                            $this->drawDebugOverlay($pdf, $pageHeight, $lineAnchor, $x);
                        }

                        $labelsDrawn++;
                    }
                } else {
                    $fallbackPages++;
                    // Fallback: fixed grid when no lines detected (e.g. image-only page)
                    $usableHeight = $pageHeight - self::TOP_MARGIN_PT - self::BOTTOM_MARGIN_PT;
                    $totalGridLines = (int) floor($usableHeight / self::LINE_HEIGHT_PT);
                    for ($lineNumber = $lineInterval; $lineNumber <= $totalGridLines; $lineNumber += $lineInterval) {
                        $yFromTop = self::TOP_MARGIN_PT + ($lineNumber * self::LINE_HEIGHT_PT);
                        $displayLabel = '-' . $lineNumber;
                        $x = $this->resolveLabelX(
                            $displayLabel,
                            $fontSizePt,
                            $margin,
                            $pageWidth,
                            $lineInsetPt,
                            $pageEdgePaddingPt,
                            $labelWidthFactor
                        );
                        $pdf->Text($x, $yFromTop, $displayLabel);

                        if ($drawDebugOverlay) {
                            $this->drawFallbackDebugOverlay($pdf, $x, $yFromTop);
                        }

                        $labelsDrawn++;
                    }
                }

                $totalLabelsDrawn += $labelsDrawn;
                $pageDiagnostics = is_array($extractorDiagnostics['pages'] ?? null)
                    ? ($extractorDiagnostics['pages'][$pageNo] ?? null)
                    : null;

                Log::debug('[LegalLine] PdfLineNumberService: page processed', [
                    'page' => $pageNo,
                    'total_pages' => $pageCount,
                    'lines_on_page' => count($lineAnchors),
                    'labels_drawn' => $labelsDrawn,
                    'page_size_pt' => ['width' => $pageWidth, 'height' => $pageHeight],
                    'extractor_page_diagnostics' => $diagnosticsEnabled ? $pageDiagnostics : null,
                ]);

                if ($onPageProcessed !== null) {
                    $onPageProcessed($pageNo, $pageCount);
                }
            }

            $pdf->Output('F', $outputPath);
            Log::info('[LegalLine] PdfLineNumberService: output written', [
                'output_path' => $outputPath,
                'total_pages' => $pageCount,
                'total_labels_drawn' => $totalLabelsDrawn,
                'fallback_pages' => $fallbackPages,
                'fallback_usage_rate' => $pageCount > 0 ? round($fallbackPages / $pageCount, 4) : 0.0,
                'normalized_input' => $compatibleSource['normalized'],
                'extractor_summary' => $diagnosticsEnabled ? [
                    'engine_preference' => $extractorDiagnostics['engine_preference'] ?? null,
                    'engine_used' => $extractorDiagnostics['engine_used'] ?? null,
                    'total_lines_detected' => $extractorDiagnostics['total_lines_detected'] ?? null,
                ] : null,
            ]);

            return $pageCount;
        } finally {
            $this->fpdiCompatibility->cleanup($compatibleSource['temporary_path']);
        }
    }

    /**
     * Yields (lineNumber => lineAnchor) for every N-th line (10, 20, 30…).
     * Line anchor carries y/x_start/x_end in PDF coordinates (origin: bottom-left).
     *
     * @param  list<array{y: float, x_start: float, x_end: float}>  $lineAnchors
     * @return \Generator<int, array{y: float, x_start: float, x_end: float}, void, void>
     */
    private function everyNthLineWithLabel(array $lineAnchors, int $interval): \Generator
    {
        $count = count($lineAnchors);
        for ($i = $interval; $i <= $count; $i += $interval) {
            $index = $i - 1; // 10th line = index 9
            yield $i => $lineAnchors[$index];
        }
    }

    private function resolveLabelX(
        string $label,
        int $fontSizePt,
        string $margin,
        float $pageWidth,
        float $lineInsetPt,
        float $pageEdgePaddingPt,
        float $labelWidthFactor
    ): float {
        $labelWidth = $this->estimateLabelWidth($label, $fontSizePt, $labelWidthFactor);
        $effectiveInset = max(0.0, $pageEdgePaddingPt + $lineInsetPt);
        $maxX = max($effectiveInset, $pageWidth - $labelWidth - $effectiveInset);

        if ($margin === 'left') {
            return $effectiveInset;
        }

        return $maxX;
    }

    private function estimateLabelWidth(string $label, int $fontSizePt, float $labelWidthFactor): float
    {
        $charCount = strlen($label);
        $effectiveFontSize = $fontSizePt > 0 ? $fontSizePt : 8;

        return $charCount * $effectiveFontSize * $labelWidthFactor;
    }

    private function lineNumberInsetPt(): float
    {
        return max(0.5, (float) config('line_numbering.line_number_inset_pt', self::LINE_NUMBER_INSET_PT));
    }

    private function pageEdgePaddingPt(): float
    {
        return max(2.0, (float) config('line_numbering.page_edge_padding_pt', self::PAGE_EDGE_PADDING_PT));
    }

    private function labelWidthFactor(): float
    {
        return max(0.35, min(1.0, (float) config('line_numbering.label_width_factor', self::LABEL_WIDTH_FACTOR)));
    }

    /**
     * @param  array{y: float, x_start: float, x_end: float}  $lineAnchor
     */
    private function drawDebugOverlay(Fpdi $pdf, float $pageHeight, array $lineAnchor, float $labelX): void
    {
        $yFromTop = $pageHeight - $lineAnchor['y'];
        $pdf->SetDrawColor(56, 189, 248);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($lineAnchor['x_start'], $yFromTop, $lineAnchor['x_end'], $yFromTop);

        $pdf->SetDrawColor(239, 68, 68);
        $pdf->Line($labelX - 2, $yFromTop, $labelX + 2, $yFromTop);
        $pdf->Line($labelX, $yFromTop - 2, $labelX, $yFromTop + 2);
    }

    private function drawFallbackDebugOverlay(Fpdi $pdf, float $x, float $yFromTop): void
    {
        $pdf->SetDrawColor(249, 115, 22);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($x - 2, $yFromTop, $x + 2, $yFromTop);
        $pdf->Line($x, $yFromTop - 2, $x, $yFromTop + 2);
    }
}

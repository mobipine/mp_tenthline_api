<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;

class PdfLineNumberService
{
    /** Fallback right margin anchor (points) when no line anchors are available. */
    private const FALLBACK_RIGHT_MARGIN_PT = 45;

    /** Gap between detected text edge and line number label (points). */
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
        private readonly PdfLineExtractor $lineExtractor
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
        Log::info('PdfLineNumberService: Starting line numbering (10th line)', [
            'input_path' => $inputPath,
            'output_path' => $outputPath,
            'line_interval' => $lineInterval,
            'margin' => $margin,
            'font_size_pt' => $fontSizePt,
        ]);

        $lineAnchorsPerPage = $this->lineExtractor->getLineAnchorsPerPage($inputPath);

        // Use points so coordinates match (extractor and FPDI both in pt)
        $pdf = new Fpdi('P', 'pt');
        $pageCount = $pdf->setSourceFile($inputPath);
        Log::debug('PdfLineNumberService: FPDI opened PDF', ['page_count' => $pageCount]);

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
                        $lineAnchor['x_start'],
                        $lineAnchor['x_end']
                    );

                    $pdf->Text($x, $yFromTop, $displayLabel);
                    $labelsDrawn++;
                }
            } else {
                // Fallback: fixed grid when no lines detected (e.g. image-only page)
                $usableHeight = $pageHeight - self::TOP_MARGIN_PT - self::BOTTOM_MARGIN_PT;
                $totalGridLines = (int) floor($usableHeight / self::LINE_HEIGHT_PT);
                for ($lineNumber = $lineInterval; $lineNumber <= $totalGridLines; $lineNumber += $lineInterval) {
                    $yFromTop = self::TOP_MARGIN_PT + ($lineNumber * self::LINE_HEIGHT_PT);
                    $displayLabel = '-' . $lineNumber;
                    $x = $margin === 'left'
                        ? self::PAGE_EDGE_PADDING_PT
                        : max(
                            self::PAGE_EDGE_PADDING_PT,
                            $pageWidth - self::FALLBACK_RIGHT_MARGIN_PT
                        );
                    $pdf->Text($x, $yFromTop, $displayLabel);
                    $labelsDrawn++;
                }
            }

            Log::debug('PdfLineNumberService: Page processed', [
                'page' => $pageNo,
                'total_pages' => $pageCount,
                'lines_on_page' => count($lineAnchors),
                'labels_drawn' => $labelsDrawn,
                'page_size_pt' => ['width' => $pageWidth, 'height' => $pageHeight],
            ]);

            if ($onPageProcessed !== null) {
                $onPageProcessed($pageNo, $pageCount);
            }
        }

        $pdf->Output('F', $outputPath);
        Log::info('PdfLineNumberService: Output written successfully', [
            'output_path' => $outputPath,
            'total_pages' => $pageCount,
        ]);

        return $pageCount;
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
        float $lineStartX,
        float $lineEndX
    ): float {
        $labelWidth = $this->estimateLabelWidth($label, $fontSizePt);

        if ($margin === 'left') {
            $x = $lineStartX - $labelWidth - self::LINE_NUMBER_INSET_PT;

            return max(self::PAGE_EDGE_PADDING_PT, $x);
        }

        $x = $lineEndX + self::LINE_NUMBER_INSET_PT;
        $maxX = $pageWidth - $labelWidth - self::PAGE_EDGE_PADDING_PT;

        return min($maxX, max(self::PAGE_EDGE_PADDING_PT, $x));
    }

    private function estimateLabelWidth(string $label, int $fontSizePt): float
    {
        $charCount = strlen($label);
        $effectiveFontSize = $fontSizePt > 0 ? $fontSizePt : 8;

        return $charCount * $effectiveFontSize * self::LABEL_WIDTH_FACTOR;
    }
}

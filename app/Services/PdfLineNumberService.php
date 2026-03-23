<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Fpdi;

class PdfLineNumberService
{
    private const LINE_NUMBER_INSET_PT = 3;
    private const PAGE_EDGE_PADDING_PT = 6;
    private const LABEL_WIDTH_FACTOR = 0.56;
    private const TOP_MARGIN_PT = 72;
    private const BOTTOM_MARGIN_PT = 72;
    private const LINE_HEIGHT_PT = 24;

    /** @var array<string, mixed> */
    private array $lastRunDiagnostics = [];

    public function __construct(
        private readonly PdfLineExtractor $lineExtractor,
        private readonly PdfFpdiCompatibilityService $fpdiCompatibility
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLastRunDiagnostics(): array
    {
        return $this->lastRunDiagnostics;
    }

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
        $lowConfidenceStrategy = $this->lowConfidencePageStrategy();
        $minimumPageConfidence = max(0.05, min(0.95, (float) config('line_numbering.minimum_page_confidence', 0.58)));

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
            'low_confidence_page_strategy' => $lowConfidenceStrategy,
            'minimum_page_confidence' => $minimumPageConfidence,
        ]);

        $compatibleSource = $this->fpdiCompatibility->resolveProcessablePath($inputPath);
        if (! $compatibleSource['processable']) {
            throw new \RuntimeException($compatibleSource['message'] ?? $this->fpdiCompatibility->unsupportedMessage());
        }

        $effectiveInputPath = $compatibleSource['path'];

        try {
            $lineAnchorsPerPage = $this->lineExtractor->getLineAnchorsPerPage($effectiveInputPath);
            $extractorDiagnostics = $this->lineExtractor->getLastDiagnostics();

            $pdf = new Fpdi('P', 'pt');
            $pageCount = $pdf->setSourceFile($effectiveInputPath);
            Log::debug('[LegalLine] PdfLineNumberService: source opened', [
                'page_count' => $pageCount,
                'normalized_input' => $compatibleSource['normalized'],
            ]);

            $fallbackPages = 0;
            $skippedLowConfidencePages = 0;
            $totalLabelsDrawn = 0;
            $runPages = [];

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);
                $pdf->AddPage($size['orientation'] ?? 'P', [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);

                $pdf->SetFont('Helvetica', '', $fontSizePt);
                $pdf->SetTextColor(80, 80, 80);

                $pageWidth = $size['width'];
                $pageHeight = $size['height'];
                $pageDiagnostics = is_array($extractorDiagnostics['pages'] ?? null)
                    ? ($extractorDiagnostics['pages'][$pageNo] ?? null)
                    : null;
                $pageConfidence = (float) ($pageDiagnostics['page_confidence'] ?? 1.0);
                $lowConfidence = $pageDiagnostics !== null
                    && (
                        ($pageDiagnostics['page_confidence_label'] ?? null) === 'low'
                        || $pageConfidence < $minimumPageConfidence
                    );
                $lineAnchors = $lineAnchorsPerPage[$pageNo] ?? [];
                $labelsDrawn = 0;
                $placementMode = 'trusted';

                if ($drawDebugOverlay && is_array($pageDiagnostics)) {
                    $this->drawPageDiagnosticsOverlay($pdf, $pageHeight, $pageDiagnostics);
                }

                if ($lowConfidence && $lowConfidenceStrategy === 'skip') {
                    $placementMode = 'skip_low_confidence';
                    $skippedLowConfidencePages++;
                } elseif ($lowConfidence && $lowConfidenceStrategy === 'fixed_grid') {
                    $placementMode = 'fallback_grid_low_confidence';
                    $fallbackPages++;
                    $labelsDrawn = $this->drawFallbackGrid(
                        $pdf,
                        $pageWidth,
                        $pageHeight,
                        $lineInterval,
                        $fontSizePt,
                        $margin,
                        $lineInsetPt,
                        $pageEdgePaddingPt,
                        $labelWidthFactor,
                        $drawDebugOverlay
                    );
                } elseif ($lineAnchors !== []) {
                    foreach ($this->everyNthLineWithLabel($lineAnchors, $lineInterval) as $lineNumber => $lineAnchor) {
                        $displayLabel = '-' . $lineNumber;
                        $yFromTop = $pageHeight - $lineAnchor['y'];
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
                    $placementMode = 'fallback_grid';
                    $fallbackPages++;
                    $labelsDrawn = $this->drawFallbackGrid(
                        $pdf,
                        $pageWidth,
                        $pageHeight,
                        $lineInterval,
                        $fontSizePt,
                        $margin,
                        $lineInsetPt,
                        $pageEdgePaddingPt,
                        $labelWidthFactor,
                        $drawDebugOverlay
                    );
                }

                $totalLabelsDrawn += $labelsDrawn;
                $runPages[$pageNo] = [
                    'page_confidence' => $pageConfidence,
                    'low_confidence' => $lowConfidence,
                    'placement_mode' => $placementMode,
                    'lines_on_page' => count($lineAnchors),
                    'labels_drawn' => $labelsDrawn,
                    'diagnostics' => $pageDiagnostics,
                ];

                Log::debug('[LegalLine] PdfLineNumberService: page processed', [
                    'page' => $pageNo,
                    'total_pages' => $pageCount,
                    'lines_on_page' => count($lineAnchors),
                    'labels_drawn' => $labelsDrawn,
                    'page_size_pt' => ['width' => $pageWidth, 'height' => $pageHeight],
                    'low_confidence' => $lowConfidence,
                    'placement_mode' => $placementMode,
                    'extractor_page_diagnostics' => $diagnosticsEnabled ? $pageDiagnostics : null,
                ]);

                if ($onPageProcessed !== null) {
                    $onPageProcessed($pageNo, $pageCount);
                }
            }

            $pdf->Output('F', $outputPath);
            $this->lastRunDiagnostics = [
                'output_path' => $outputPath,
                'page_count' => $pageCount,
                'total_labels_drawn' => $totalLabelsDrawn,
                'fallback_pages' => $fallbackPages,
                'skipped_low_confidence_pages' => $skippedLowConfidencePages,
                'pages' => $runPages,
                'extractor_summary' => [
                    'engine_preference' => $extractorDiagnostics['engine_preference'] ?? null,
                    'engine_used' => $extractorDiagnostics['engine_used'] ?? null,
                    'total_lines_detected' => $extractorDiagnostics['total_lines_detected'] ?? null,
                ],
            ];

            Log::info('[LegalLine] PdfLineNumberService: output written', [
                'output_path' => $outputPath,
                'total_pages' => $pageCount,
                'total_labels_drawn' => $totalLabelsDrawn,
                'fallback_pages' => $fallbackPages,
                'skipped_low_confidence_pages' => $skippedLowConfidencePages,
                'fallback_usage_rate' => $pageCount > 0 ? round($fallbackPages / $pageCount, 4) : 0.0,
                'normalized_input' => $compatibleSource['normalized'],
                'extractor_summary' => $diagnosticsEnabled ? $this->lastRunDiagnostics['extractor_summary'] : null,
            ]);

            return $pageCount;
        } finally {
            $this->fpdiCompatibility->cleanup($compatibleSource['temporary_path']);
        }
    }

    /**
     * @param  list<array{y: float, x_start: float, x_end: float}>  $lineAnchors
     * @return \Generator<int, array{y: float, x_start: float, x_end: float}, void, void>
     */
    private function everyNthLineWithLabel(array $lineAnchors, int $interval): \Generator
    {
        $count = count($lineAnchors);
        for ($index = $interval; $index <= $count; $index += $interval) {
            yield $index => $lineAnchors[$index - 1];
        }
    }

    private function drawFallbackGrid(
        Fpdi $pdf,
        float $pageWidth,
        float $pageHeight,
        int $lineInterval,
        int $fontSizePt,
        string $margin,
        float $lineInsetPt,
        float $pageEdgePaddingPt,
        float $labelWidthFactor,
        bool $drawDebugOverlay
    ): int {
        $usableHeight = $pageHeight - self::TOP_MARGIN_PT - self::BOTTOM_MARGIN_PT;
        $totalGridLines = (int) floor($usableHeight / self::LINE_HEIGHT_PT);
        $labelsDrawn = 0;

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

        return $labelsDrawn;
    }

    /**
     * @param  array<string, mixed>  $pageDiagnostics
     */
    private function drawPageDiagnosticsOverlay(Fpdi $pdf, float $pageHeight, array $pageDiagnostics): void
    {
        $lines = is_array($pageDiagnostics['scored_lines'] ?? null) ? $pageDiagnostics['scored_lines'] : [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $reason = (string) ($line['suppressed_reason'] ?? '');
            $yFromTop = $pageHeight - (float) ($line['y'] ?? 0.0);
            $xStart = (float) ($line['x_start'] ?? 0.0);
            $xEnd = (float) ($line['x_end'] ?? 0.0);

            if ($reason === 'header') {
                $pdf->SetDrawColor(245, 158, 11);
            } elseif ($reason === 'footer') {
                $pdf->SetDrawColor(234, 88, 12);
            } elseif ($reason === 'structured_content') {
                $pdf->SetDrawColor(168, 85, 247);
            } elseif ($reason !== '') {
                $pdf->SetDrawColor(148, 163, 184);
            } else {
                $pdf->SetDrawColor(34, 197, 94);
            }

            $pdf->SetLineWidth(0.25);
            $pdf->Line($xStart, $yFromTop, $xEnd, $yFromTop);
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

    private function lowConfidencePageStrategy(): string
    {
        $default = (bool) config('line_numbering.skip_low_confidence_pages', false) ? 'skip' : 'number';
        $strategy = strtolower((string) config('line_numbering.low_confidence_page_strategy', $default));

        return in_array($strategy, ['skip', 'fixed_grid', 'number'], true) ? $strategy : $default;
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

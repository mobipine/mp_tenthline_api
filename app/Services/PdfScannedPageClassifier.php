<?php

namespace App\Services;

class PdfScannedPageClassifier
{
    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $layout
     * @param  list<array<string, mixed>>  $trustedLines
     * @param  list<array{y: float, x_start: float, x_end: float}>  $trustedAnchors
     * @return array<string, mixed>
     */
    public function classify(array $page, array $layout, array $trustedLines, array $trustedAnchors): array
    {
        if (($page['engine'] ?? null) !== 'ocr') {
            return [
                'type' => 'not_scanned',
                'should_number' => true,
                'confidence' => 1.0,
                'reason' => null,
                'metrics' => [],
            ];
        }

        $pageWidth = max(1.0, (float) ($layout['page_width'] ?? 0.0));
        $pageHeight = max(1.0, (float) ($layout['page_height'] ?? 0.0));
        $bodyRegion = is_array($layout['body_region'] ?? null) ? $layout['body_region'] : [
            'left' => 0.0,
            'right' => $pageWidth,
            'top' => $pageHeight,
            'bottom' => 0.0,
        ];
        $tableRowCount = max(0, (int) ($layout['table_row_count'] ?? 0));
        $trustedCount = count($trustedLines);
        $trustedAnchorCount = count($trustedAnchors);
        $medianSpacing = max(0.0, (float) ($layout['median_line_spacing'] ?? 0.0));

        $lineWidths = array_map(
            static fn (array $line): float => max(0.0, ((float) ($line['x_end'] ?? 0.0)) - ((float) ($line['x_start'] ?? 0.0))),
            $trustedLines
        );
        $medianWidth = $this->median($lineWidths);
        $averageWidth = $trustedCount > 0 ? array_sum($lineWidths) / $trustedCount : 0.0;
        $medianWidthRatio = $medianWidth / $pageWidth;
        $averageWidthRatio = $averageWidth / $pageWidth;
        $bodyCoverageRatio = max(
            0.0,
            min(1.0, (((float) ($bodyRegion['top'] ?? 0.0)) - ((float) ($bodyRegion['bottom'] ?? 0.0))) / $pageHeight)
        );
        $bottomWhitespaceRatio = max(0.0, min(1.0, ((float) ($bodyRegion['bottom'] ?? 0.0)) / $pageHeight));

        $shortLineCount = 0;
        $uppercaseLineCount = 0;
        $colonLineCount = 0;
        $addressSignalCount = 0;
        $denseBodySignalCount = 0;

        foreach ($trustedLines as $line) {
            $text = trim((string) ($line['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $lineWidthRatio = max(0.0, (((float) ($line['x_end'] ?? 0.0)) - ((float) ($line['x_start'] ?? 0.0))) / $pageWidth);
            if ($lineWidthRatio < 0.42) {
                $shortLineCount++;
            }

            if (str_contains($text, ':')) {
                $colonLineCount++;
            }

            if ($this->isMostlyUppercase($text)) {
                $uppercaseLineCount++;
            }

            if ($this->containsAddressSignal($text)) {
                $addressSignalCount++;
            }

            if ($lineWidthRatio >= 0.55 && preg_match('/[a-z]{3,}/u', $text) === 1) {
                $denseBodySignalCount++;
            }
        }

        $shortLineRatio = $trustedCount > 0 ? $shortLineCount / $trustedCount : 0.0;
        $uppercaseLineRatio = $trustedCount > 0 ? $uppercaseLineCount / $trustedCount : 0.0;
        $colonLineRatio = $trustedCount > 0 ? $colonLineCount / $trustedCount : 0.0;
        $denseBodySignalRatio = $trustedCount > 0 ? $denseBodySignalCount / $trustedCount : 0.0;

        [$largestGapRatio, $largeGapRatio] = $this->gapMetrics($trustedLines, $medianSpacing, $pageHeight);

        $metrics = [
            'trusted_line_count' => $trustedCount,
            'trusted_anchor_count' => $trustedAnchorCount,
            'median_width_ratio' => round($medianWidthRatio, 4),
            'average_width_ratio' => round($averageWidthRatio, 4),
            'body_coverage_ratio' => round($bodyCoverageRatio, 4),
            'bottom_whitespace_ratio' => round($bottomWhitespaceRatio, 4),
            'short_line_ratio' => round($shortLineRatio, 4),
            'uppercase_line_ratio' => round($uppercaseLineRatio, 4),
            'colon_line_ratio' => round($colonLineRatio, 4),
            'dense_body_signal_ratio' => round($denseBodySignalRatio, 4),
            'address_signal_count' => $addressSignalCount,
            'largest_gap_ratio' => round($largestGapRatio, 4),
            'large_gap_ratio' => round($largeGapRatio, 4),
            'table_row_count' => $tableRowCount,
        ];

        if ((bool) ($layout['multi_column_suspected'] ?? false)) {
            return [
                'type' => 'structured_layout',
                'should_number' => false,
                'confidence' => 0.92,
                'reason' => 'structured_layout_detected',
                'metrics' => $metrics,
            ];
        }

        if ((bool) ($layout['table_suspected'] ?? false) && $tableRowCount >= 3) {
            return [
                'type' => 'body_text_with_table',
                'should_number' => true,
                'confidence' => 0.79,
                'reason' => null,
                'metrics' => $metrics,
            ];
        }

        if ($addressSignalCount >= 2
            && ($shortLineRatio >= 0.45 || $bottomWhitespaceRatio >= 0.22 || $bodyCoverageRatio <= 0.55)) {
            return [
                'type' => 'service_or_address_block',
                'should_number' => false,
                'confidence' => 0.94,
                'reason' => 'address_or_service_pattern',
                'metrics' => $metrics,
            ];
        }

        if ($trustedCount < 12 && $bodyCoverageRatio < 0.34) {
            return [
                'type' => 'front_matter_or_sparse',
                'should_number' => false,
                'confidence' => 0.88,
                'reason' => 'too_sparse_for_body_page',
                'metrics' => $metrics,
            ];
        }

        if ($largeGapRatio > 0.24 && $shortLineRatio > 0.48) {
            return [
                'type' => 'structured_sparse',
                'should_number' => false,
                'confidence' => 0.86,
                'reason' => 'irregular_vertical_structure',
                'metrics' => $metrics,
            ];
        }

        if ($medianWidthRatio < 0.34 && $denseBodySignalRatio < 0.35 && $trustedCount < 18) {
            return [
                'type' => 'structured_sparse',
                'should_number' => false,
                'confidence' => 0.8,
                'reason' => 'insufficient_body_line_width',
                'metrics' => $metrics,
            ];
        }

        if ($bottomWhitespaceRatio > 0.24 && $trustedCount < 16 && $denseBodySignalRatio < 0.4) {
            return [
                'type' => 'non_body_sparse',
                'should_number' => false,
                'confidence' => 0.78,
                'reason' => 'large_tail_whitespace',
                'metrics' => $metrics,
            ];
        }

        return [
            'type' => 'body_text',
            'should_number' => true,
            'confidence' => 0.76,
            'reason' => null,
            'metrics' => $metrics,
        ];
    }

    private function containsAddressSignal(string $text): bool
    {
        $patterns = [
            '/\badvocates?\b/i',
            '/\bdrawn\s+and\s+filed\b/i',
            '/\bcopies\s+to\s+be\s+served\b/i',
            '/\bserved\s+upon\b/i',
            '/\bp\.?\s*o\.?\s*box\b/i',
            '/\broad\b/i',
            '/\bapartment(s)?\b/i',
            '/\bfloor\b/i',
            '/\btel[:\s]/i',
            '/@/i',
            '/\bllp\b/i',
            '/\bcompany\b/i',
            '/\bchambers\b/i',
            '/\bnairobi\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isMostlyUppercase(string $text): bool
    {
        preg_match_all('/\p{Lu}/u', $text, $upper);
        preg_match_all('/\p{L}/u', $text, $letters);

        $letterCount = count($letters[0] ?? []);
        if ($letterCount < 4) {
            return false;
        }

        return (count($upper[0] ?? []) / $letterCount) >= 0.78;
    }

    /**
     * @param  list<array<string, mixed>>  $trustedLines
     * @return array{0: float, 1: float}
     */
    private function gapMetrics(array $trustedLines, float $medianSpacing, float $pageHeight): array
    {
        if (count($trustedLines) < 2 || $medianSpacing <= 0.0) {
            return [0.0, 0.0];
        }

        $lines = $trustedLines;
        usort($lines, static fn (array $a, array $b): int => ((float) ($b['y'] ?? 0.0)) <=> ((float) ($a['y'] ?? 0.0)));

        $largestGap = 0.0;
        $largeGapCount = 0;
        $gapCount = 0;

        for ($index = 0, $max = count($lines) - 1; $index < $max; $index++) {
            $delta = ((float) ($lines[$index]['y'] ?? 0.0)) - ((float) ($lines[$index + 1]['y'] ?? 0.0));
            if ($delta <= 0.5) {
                continue;
            }

            $gapCount++;
            $largestGap = max($largestGap, $delta);
            if (($delta / $medianSpacing) > 1.9) {
                $largeGapCount++;
            }
        }

        return [
            $pageHeight > 0 ? min(1.0, $largestGap / $pageHeight) : 0.0,
            $gapCount > 0 ? ($largeGapCount / $gapCount) : 0.0,
        ];
    }

    /**
     * @param  list<float>  $numbers
     */
    private function median(array $numbers): float
    {
        if ($numbers === []) {
            return 0.0;
        }

        sort($numbers, SORT_NUMERIC);
        $count = count($numbers);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($numbers[$mid - 1] + $numbers[$mid]) / 2;
        }

        return $numbers[$mid];
    }
}

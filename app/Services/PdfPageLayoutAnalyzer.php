<?php

namespace App\Services;

class PdfPageLayoutAnalyzer
{
    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, string>  $artifactMarks
     * @return array<string, mixed>
     */
    public function analyze(array $page, array $artifactMarks = []): array
    {
        $rawLines = array_values(array_filter(
            is_array($page['raw_lines'] ?? null) ? $page['raw_lines'] : [],
            static fn (mixed $line): bool => is_array($line)
        ));

        $pageWidth = $this->resolvePageWidth($page, $rawLines);
        $pageHeight = $this->resolvePageHeight($page, $rawLines);

        $candidateLines = array_values(array_filter($rawLines, static function (array $line) use ($artifactMarks): bool {
            $lineId = (string) ($line['id'] ?? '');

            return $lineId === '' || ! isset($artifactMarks[$lineId]);
        }));

        if ($candidateLines === []) {
            $candidateLines = $rawLines;
        }

        $widths = array_map(
            static fn (array $line): float => max(0.0, ((float) ($line['x_end'] ?? 0.0)) - ((float) ($line['x_start'] ?? 0.0))),
            $candidateLines
        );
        $medianWidth = max(1.0, $this->median($widths));

        $bodyCandidates = array_values(array_filter($candidateLines, static function (array $line) use ($medianWidth): bool {
            $width = max(0.0, ((float) ($line['x_end'] ?? 0.0)) - ((float) ($line['x_start'] ?? 0.0)));
            $charCount = (int) ($line['char_count'] ?? 0);

            return $charCount >= 6 || $width >= max(24.0, $medianWidth * 0.35);
        }));

        if (count($bodyCandidates) < 3) {
            $bodyCandidates = $candidateLines;
        }

        $columnData = $this->detectColumns($bodyCandidates, $pageWidth);
        $dominantLines = $columnData['dominant_lines'];
        if ($dominantLines === []) {
            $dominantLines = $bodyCandidates;
        }

        $bodyRegion = $this->buildBodyRegion($dominantLines, $columnData['bounds'], $pageWidth, $pageHeight);
        $medianSpacing = $this->medianLineSpacing($dominantLines);

        $tableLikeCount = 0;
        foreach ($candidateLines as $line) {
            if ($this->isTableLike($line, $pageWidth)) {
                $tableLikeCount++;
            }
        }

        $candidateCount = max(1, count($candidateLines));
        $tableLikeRatio = $tableLikeCount / $candidateCount;
        $tableRows = $this->detectTableRows($candidateLines, $pageWidth);

        return [
            'page_width' => $pageWidth,
            'page_height' => $pageHeight,
            'body_region' => $bodyRegion,
            'median_line_spacing' => $medianSpacing,
            'multi_column_suspected' => (bool) $columnData['suspected'],
            'column_gap_pt' => (float) $columnData['gap'],
            'dominant_column' => $columnData['bounds'],
            'table_suspected' => ($tableLikeCount >= 3 && $tableLikeRatio >= 0.18)
                || $tableRows['count'] >= 3,
            'table_like_ratio' => round($tableLikeRatio, 3),
            'table_row_count' => $tableRows['count'],
            'table_row_ratio' => round($tableRows['ratio'], 3),
            'table_row_anchors' => $tableRows['anchors'],
            'table_row_line_ids' => $tableRows['line_ids'],
            'body_candidate_count' => count($dominantLines),
            'raw_candidate_count' => count($candidateLines),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{suspected: bool, gap: float, bounds: array<string, float>|null, dominant_lines: list<array<string, mixed>>}
     */
    private function detectColumns(array $lines, float $pageWidth): array
    {
        if (count($lines) < 6) {
            return [
                'suspected' => false,
                'gap' => 0.0,
                'bounds' => null,
                'dominant_lines' => $lines,
            ];
        }

        $withCenters = array_map(static function (array $line): array {
            $xStart = (float) ($line['x_start'] ?? 0.0);
            $xEnd = (float) ($line['x_end'] ?? 0.0);

            return $line + [
                'x_center' => ($xStart + $xEnd) / 2,
            ];
        }, $lines);

        usort($withCenters, static fn (array $a, array $b): int => ((float) $a['x_center']) <=> ((float) $b['x_center']));

        $largestGap = 0.0;
        $largestGapIndex = -1;

        for ($index = 0, $max = count($withCenters) - 1; $index < $max; $index++) {
            $gap = ((float) $withCenters[$index + 1]['x_center']) - ((float) $withCenters[$index]['x_center']);
            if ($gap > $largestGap) {
                $largestGap = $gap;
                $largestGapIndex = $index;
            }
        }

        $gapThreshold = max(42.0, $pageWidth * 0.12);
        if ($largestGapIndex === -1 || $largestGap < $gapThreshold) {
            return [
                'suspected' => false,
                'gap' => $largestGap,
                'bounds' => null,
                'dominant_lines' => $lines,
            ];
        }

        $leftCluster = array_slice($withCenters, 0, $largestGapIndex + 1);
        $rightCluster = array_slice($withCenters, $largestGapIndex + 1);
        $minimumClusterSize = max(2, (int) floor(count($withCenters) / 3));

        if (count($leftCluster) < $minimumClusterSize || count($rightCluster) < $minimumClusterSize) {
            return [
                'suspected' => false,
                'gap' => $largestGap,
                'bounds' => null,
                'dominant_lines' => $lines,
            ];
        }

        $leftWidth = array_sum(array_map(
            static fn (array $line): float => max(0.0, ((float) $line['x_end']) - ((float) $line['x_start'])),
            $leftCluster
        ));
        $rightWidth = array_sum(array_map(
            static fn (array $line): float => max(0.0, ((float) $line['x_end']) - ((float) $line['x_start'])),
            $rightCluster
        ));

        $dominantCluster = count($leftCluster) > count($rightCluster)
            ? $leftCluster
            : (count($leftCluster) < count($rightCluster) ? $rightCluster : ($leftWidth >= $rightWidth ? $leftCluster : $rightCluster));

        return [
            'suspected' => true,
            'gap' => $largestGap,
            'bounds' => [
                'left' => max(0.0, min(array_map(static fn (array $line): float => (float) $line['x_start'], $dominantCluster)) - 12.0),
                'right' => min($pageWidth, max(array_map(static fn (array $line): float => (float) $line['x_end'], $dominantCluster)) + 12.0),
            ],
            'dominant_lines' => array_values($dominantCluster),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, float>|null  $columnBounds
     * @return array{left: float, right: float, top: float, bottom: float}
     */
    private function buildBodyRegion(array $lines, ?array $columnBounds, float $pageWidth, float $pageHeight): array
    {
        if ($lines === []) {
            return [
                'left' => 0.0,
                'right' => $pageWidth,
                'top' => $pageHeight,
                'bottom' => 0.0,
            ];
        }

        $leftPadding = max(6.0, (float) config('line_numbering.body_region_side_padding_pt', 10.0));
        $topPadding = max(0.0, (float) config('line_numbering.body_region_top_padding_pt', 10.0));
        $bottomPadding = max(0.0, (float) config('line_numbering.body_region_bottom_padding_pt', 12.0));

        $left = $this->percentile(array_map(static fn (array $line): float => (float) $line['x_start'], $lines), 0.15) - $leftPadding;
        $right = $this->percentile(array_map(static fn (array $line): float => (float) $line['x_end'], $lines), 0.85) + $leftPadding;
        if ($columnBounds !== null) {
            $left = max($left, (float) ($columnBounds['left'] ?? $left));
            $right = min($right, (float) ($columnBounds['right'] ?? $right));
        }

        $top = max(array_map(static fn (array $line): float => (float) ($line['top'] ?? $line['y'] ?? 0.0), $lines)) + $topPadding;
        $bottom = min(array_map(static fn (array $line): float => (float) ($line['bottom'] ?? $line['y'] ?? 0.0), $lines)) - $bottomPadding;

        return [
            'left' => max(0.0, $left),
            'right' => min($pageWidth, max($left + 24.0, $right)),
            'top' => min($pageHeight, max(0.0, $top)),
            'bottom' => max(0.0, min($pageHeight, $bottom)),
        ];
    }

    private function isTableLike(array $line, float $pageWidth): bool
    {
        $words = is_array($line['words'] ?? null) ? $line['words'] : [];
        if (count($words) < 3) {
            return false;
        }

        usort($words, static fn (array $a, array $b): int => ((float) ($a['x_start'] ?? 0.0)) <=> ((float) ($b['x_start'] ?? 0.0)));

        $wideGapCount = 0;
        $gapThreshold = max(24.0, $pageWidth * 0.035);

        for ($index = 0, $max = count($words) - 1; $index < $max; $index++) {
            $gap = ((float) ($words[$index + 1]['x_start'] ?? 0.0)) - ((float) ($words[$index]['x_end'] ?? 0.0));
            if ($gap >= $gapThreshold) {
                $wideGapCount++;
            }
        }

        return $wideGapCount >= 2;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{count: int, ratio: float, anchors: list<array<string, mixed>>, line_ids: list<string>}
     */
    private function detectTableRows(array $lines, float $pageWidth): array
    {
        if (count($lines) < 3) {
            return [
                'count' => 0,
                'ratio' => 0.0,
                'anchors' => [],
                'line_ids' => [],
            ];
        }

        $heights = array_map(static fn (array $line): float => max(1.0, (float) ($line['height'] ?? 0.0)), $lines);
        $rowTolerance = max(6.0, $this->median($heights) * 0.7);

        usort($lines, static function (array $a, array $b) use ($rowTolerance): int {
            $dy = ((float) ($b['y'] ?? 0.0)) - ((float) ($a['y'] ?? 0.0));
            if (abs($dy) > $rowTolerance) {
                return $dy > 0 ? 1 : -1;
            }

            return ((float) ($a['x_start'] ?? 0.0)) <=> ((float) ($b['x_start'] ?? 0.0));
        });

        $rows = [];
        $currentRowY = null;
        $currentRow = [];

        foreach ($lines as $line) {
            $lineY = (float) ($line['y'] ?? 0.0);
            if ($currentRowY === null || abs($lineY - $currentRowY) > $rowTolerance) {
                if ($currentRow !== []) {
                    $rows[] = $currentRow;
                }

                $currentRow = [$line];
                $currentRowY = $lineY;
                continue;
            }

            $currentRow[] = $line;
        }

        if ($currentRow !== []) {
            $rows[] = $currentRow;
        }

        $tableRowCount = 0;
        $tableRowAnchors = [];
        $tableRowLineIds = [];
        $gapThreshold = max(48.0, $pageWidth * 0.12);

        foreach ($rows as $row) {
            usort($row, static fn (array $a, array $b): int => ((float) ($a['x_start'] ?? 0.0)) <=> ((float) ($b['x_start'] ?? 0.0)));

            $wideGapCount = 0;
            for ($index = 0, $max = count($row) - 1; $index < $max; $index++) {
                $gap = ((float) ($row[$index + 1]['x_start'] ?? 0.0)) - ((float) ($row[$index]['x_end'] ?? 0.0));
                if ($gap >= $gapThreshold) {
                    $wideGapCount++;
                }
            }

            $rowSpan = ((float) ($row[array_key_last($row)]['x_end'] ?? 0.0)) - ((float) ($row[0]['x_start'] ?? 0.0));
            $containsTableLikeLine = count(array_filter(
                $row,
                fn (array $line): bool => $this->isTableLike($line, $pageWidth)
            )) > 0;

            $qualifiesAsTableRow = (count($row) >= 3 && $wideGapCount >= 2 && $rowSpan >= ($pageWidth * 0.45))
                || (count($row) >= 2 && $wideGapCount >= 1 && $rowSpan >= ($pageWidth * 0.35))
                || (count($row) === 1 && $containsTableLikeLine);

            if (! $qualifiesAsTableRow) {
                continue;
            }

            $tableRowCount++;
            $tableRowAnchors[] = [
                'id' => 'table-row-' . $tableRowCount,
                'text' => trim(implode(' ', array_map(
                    static fn (array $line): string => trim((string) ($line['text'] ?? '')),
                    $row
                ))),
                'y' => round($this->median(array_map(static fn (array $line): float => (float) ($line['y'] ?? 0.0), $row)), 3),
                'x_start' => round(min(array_map(static fn (array $line): float => (float) ($line['x_start'] ?? 0.0), $row)), 3),
                'x_end' => round(max(array_map(static fn (array $line): float => (float) ($line['x_end'] ?? 0.0), $row)), 3),
            ];

            foreach ($row as $line) {
                $lineId = trim((string) ($line['id'] ?? ''));
                if ($lineId !== '') {
                    $tableRowLineIds[] = $lineId;
                }
            }
        }

        $rowCount = max(1, count($rows));

        return [
            'count' => $tableRowCount,
            'ratio' => $tableRowCount / $rowCount,
            'anchors' => $tableRowAnchors,
            'line_ids' => array_values(array_unique($tableRowLineIds)),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $lines
     */
    private function resolvePageWidth(array $page, array $lines): float
    {
        $pageWidth = (float) ($page['page_width'] ?? 0.0);
        if ($pageWidth > 0) {
            return $pageWidth;
        }

        $maxX = 0.0;
        foreach ($lines as $line) {
            $maxX = max($maxX, (float) ($line['x_end'] ?? 0.0));
        }

        return max(72.0, $maxX + 72.0);
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $lines
     */
    private function resolvePageHeight(array $page, array $lines): float
    {
        $pageHeight = (float) ($page['page_height'] ?? 0.0);
        if ($pageHeight > 0) {
            return $pageHeight;
        }

        $maxY = 0.0;
        foreach ($lines as $line) {
            $maxY = max($maxY, (float) ($line['top'] ?? $line['y'] ?? 0.0));
        }

        return max(72.0, $maxY + 72.0);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function medianLineSpacing(array $lines): float
    {
        if (count($lines) < 2) {
            return 0.0;
        }

        usort($lines, static fn (array $a, array $b): int => ((float) ($b['y'] ?? 0.0)) <=> ((float) ($a['y'] ?? 0.0)));

        $spacings = [];
        for ($index = 0, $max = count($lines) - 1; $index < $max; $index++) {
            $delta = ((float) $lines[$index]['y']) - ((float) $lines[$index + 1]['y']);
            if ($delta > 0.5) {
                $spacings[] = $delta;
            }
        }

        return $this->median($spacings);
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $count = count($values);
        $index = (int) floor(($count - 1) * max(0.0, min(1.0, $percentile)));

        return $values[$index] ?? $values[array_key_last($values)];
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

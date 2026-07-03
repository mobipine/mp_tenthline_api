<?php

namespace App\Services\Scanned;

class PaddleOcrLineNormalizer
{
    /**
     * Normalize the raw lines array returned from PaddleOCR sidecar.
     *
     * PaddleOCR emits one bounding box per detected text fragment, and a single
     * visual line frequently yields several fragments. Because tenthlining
     * counts text lines as an ordinal running total, every stray fragment shifts
     * the numbering below it. We therefore cluster fragments that share a visual
     * row into a single numbered line ("one visual row = one line") before the
     * page is scored and numbered.
     *
     * @param array{lines: list<array{bbox: list<list<float>>, text: string, confidence: float}>} $ocrData
     * @param array{width: float, height: float} $dimensions PDF page dimensions in points
     * @param int $imageWidth image width in pixels
     * @param int $imageHeight image height in pixels
     * @param int $pageNo page number
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function normalizePage(
        array $ocrData,
        array $dimensions,
        int $imageWidth,
        int $imageHeight,
        int $pageNo,
        array $options = []
    ): array {
        $minimumLineConfidence = (float) ($options['minimum_line_confidence'] ?? config('paddleocr.min_confidence', 0.0));
        $baselineRatio = (float) ($options['baseline_ratio'] ?? config('paddleocr.baseline_ratio', 0.82));

        $pageWidthPt = max(1.0, (float) $dimensions['width']);
        $pageHeightPt = max(1.0, (float) $dimensions['height']);

        $scaleX = $pageWidthPt / max(1.0, $imageWidth);
        $scaleY = $pageHeightPt / max(1.0, $imageHeight);

        $lines = is_array($ocrData['lines'] ?? null) ? $ocrData['lines'] : [];

        $boxes = [];
        $filteredLowConfidence = 0;

        foreach ($lines as $line) {
            $text = $this->normalizeText($line['text'] ?? '');
            if ($text === '') {
                continue;
            }

            $confidence = is_numeric($line['confidence'] ?? null) ? (float) $line['confidence'] : 0.0;
            if ($confidence < $minimumLineConfidence) {
                $filteredLowConfidence++;
                continue;
            }

            $bbox = is_array($line['bbox'] ?? null) ? $line['bbox'] : null;
            if ($bbox === null || count($bbox) < 4) {
                continue;
            }

            // bbox is typically: [[x1, y1], [x2, y2], [x3, y3], [x4, y4]]
            $xs = array_column($bbox, 0);
            $ys = array_column($bbox, 1);

            $xStartPt = min($xs) * $scaleX;
            $xEndPt = max($xs) * $scaleX;

            // Flip Y axis: PDF coordinate 0 is bottom, image coordinate 0 is top.
            $topPt = $pageHeightPt - (min($ys) * $scaleY);
            $bottomPt = $pageHeightPt - (max($ys) * $scaleY);
            $heightPt = max(0.1, $topPt - $bottomPt);

            $boxes[] = [
                'text' => $text,
                'x_start' => $xStartPt,
                'x_end' => $xEndPt,
                'top' => $topPt,
                'bottom' => $bottomPt,
                'height' => $heightPt,
                'confidence' => $confidence,
            ];
        }

        $medianHeight = $this->medianHeight($boxes);

        if ($this->rowClusterEnabled($options)) {
            $boxes = $this->splitTallBoxes($boxes, $medianHeight, $options);
            $rows = $this->clusterRows($boxes, $medianHeight, $options);
        } else {
            $rows = array_map(static fn (array $box): array => [$box], $boxes);
        }

        $rawLines = [];
        $lineConfidenceTotal = 0.0;
        $wordIndexOffset = 0;

        foreach ($rows as $lineIdx => $rowBoxes) {
            $merged = $this->mergeRow($rowBoxes, $baselineRatio);
            if ($merged === null) {
                continue;
            }

            $tempLine = [
                'id' => "p{$pageNo}-paddleocr-{$lineIdx}",
                'text' => $merged['text'],
                'x_start' => round($merged['x_start'], 3),
                'x_end' => round($merged['x_end'], 3),
                'y' => round($merged['y'], 3),
                'top' => round($merged['top'], 3),
                'bottom' => round($merged['bottom'], 3),
                'height' => round($merged['height'], 3),
                'char_count' => $this->stringLength($merged['text']),
                'source' => 'paddleocr',
                'confidence' => round($merged['confidence'], 3),
                'fragment_count' => count($rowBoxes),
            ];

            $words = $this->splitLineIntoWords($tempLine, $pageNo, $wordIndexOffset);
            $wordIndexOffset += count($words);
            $tempLine['words'] = $words;

            $lineConfidenceTotal += $merged['confidence'];
            $rawLines[] = $tempLine;
        }

        // Sort lines from top to bottom (y descending in PDF coordinate system).
        usort($rawLines, static function (array $a, array $b): int {
            $dy = ((float) $b['y']) <=> ((float) $a['y']);
            if ($dy !== 0) {
                return $dy;
            }
            return ((float) $a['x_start']) <=> ((float) $b['x_start']);
        });

        return [
            'page_no' => $pageNo,
            'engine' => 'ocr',
            'ocr_provider' => 'paddleocr',
            'page_width' => $pageWidthPt,
            'page_height' => $pageHeightPt,
            'page_rotation' => 0,
            'raw_lines' => $rawLines,
            'diagnostic' => [
                'engine' => 'ocr',
                'ocr_provider' => 'paddleocr',
                'paddleocr_fragments_seen' => count($lines),
                'paddleocr_fragments_retained' => count($boxes),
                'paddleocr_lines_retained' => count($rawLines),
                'paddleocr_lines_filtered_low_confidence' => $filteredLowConfidence,
                'paddleocr_row_clustering' => $this->rowClusterEnabled($options),
                'paddleocr_median_line_height' => round($medianHeight, 3),
                'paddleocr_average_line_confidence' => count($rawLines) > 0
                    ? round($lineConfidenceTotal / count($rawLines), 3)
                    : 0.0,
            ],
        ];
    }

    /**
     * Group fragments that share a visual row into a single line.
     *
     * @param  list<array<string, mixed>>  $boxes
     * @return list<list<array<string, mixed>>>
     */
    private function clusterRows(array $boxes, float $medianHeight, array $options): array
    {
        if ($boxes === []) {
            return [];
        }

        $overlapRatio = (float) ($options['row_cluster_overlap_ratio'] ?? config('paddleocr.row_cluster_overlap_ratio', 0.35));
        $baselineFactor = (float) ($options['row_cluster_baseline_factor'] ?? config('paddleocr.row_cluster_baseline_factor', 0.5));
        $baselineTolerance = max(1.0, $medianHeight * max(0.05, $baselineFactor));

        // Sort top to bottom so a greedy sweep sees rows in reading order.
        usort($boxes, static fn (array $a, array $b): int => ((float) $b['top']) <=> ((float) $a['top']));

        $clusters = [];
        foreach ($boxes as $box) {
            $placed = false;

            foreach ($clusters as $index => $cluster) {
                if ($this->belongsToRow($box, $cluster, $overlapRatio, $baselineTolerance)) {
                    $clusters[$index]['boxes'][] = $box;
                    $clusters[$index]['top'] = max($cluster['top'], (float) $box['top']);
                    $clusters[$index]['bottom'] = min($cluster['bottom'], (float) $box['bottom']);
                    $clusters[$index]['center'] = ($clusters[$index]['top'] + $clusters[$index]['bottom']) / 2;
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $clusters[] = [
                    'top' => (float) $box['top'],
                    'bottom' => (float) $box['bottom'],
                    'center' => (((float) $box['top']) + ((float) $box['bottom'])) / 2,
                    'boxes' => [$box],
                ];
            }
        }

        return array_map(static fn (array $cluster): array => $cluster['boxes'], $clusters);
    }

    /**
     * @param  array<string, mixed>  $box
     * @param  array{top: float, bottom: float, center: float, boxes: list<array<string, mixed>>}  $cluster
     */
    private function belongsToRow(array $box, array $cluster, float $overlapRatio, float $baselineTolerance): bool
    {
        $boxTop = (float) $box['top'];
        $boxBottom = (float) $box['bottom'];
        $boxHeight = max(0.1, $boxTop - $boxBottom);
        $clusterHeight = max(0.1, $cluster['top'] - $cluster['bottom']);

        $overlap = min($boxTop, $cluster['top']) - max($boxBottom, $cluster['bottom']);
        if ($overlap > 0 && $overlap >= $overlapRatio * min($boxHeight, $clusterHeight)) {
            return true;
        }

        $boxCenter = ($boxTop + $boxBottom) / 2;

        return abs($boxCenter - $cluster['center']) <= $baselineTolerance;
    }

    /**
     * Split a fragment that is tall enough to span several rows into evenly
     * spaced bands so the row is not undercounted.
     *
     * @param  list<array<string, mixed>>  $boxes
     * @return list<array<string, mixed>>
     */
    private function splitTallBoxes(array $boxes, float $medianHeight, array $options): array
    {
        $splitFactor = (float) ($options['row_split_height_factor'] ?? config('paddleocr.row_split_height_factor', 1.7));
        if ($medianHeight <= 0.0 || $splitFactor <= 0.0) {
            return $boxes;
        }

        $result = [];
        foreach ($boxes as $box) {
            $height = (float) $box['height'];
            $bands = (int) round($height / $medianHeight);

            if ($bands < 2 || $height < $splitFactor * $medianHeight) {
                $result[] = $box;
                continue;
            }

            $result = array_merge($result, $this->splitBoxIntoBands($box, $bands));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $box
     * @return list<array<string, mixed>>
     */
    private function splitBoxIntoBands(array $box, int $bands): array
    {
        $top = (float) $box['top'];
        $bottom = (float) $box['bottom'];
        $bandHeight = ($top - $bottom) / $bands;

        // Distribute the words roughly evenly across bands, top to bottom, so
        // every band carries plausible text and is retained during scoring.
        $words = preg_split('/\s+/u', (string) $box['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $perBand = max(1, (int) ceil(count($words) / $bands));

        $result = [];
        for ($band = 0; $band < $bands; $band++) {
            $bandTop = $top - ($band * $bandHeight);
            $bandBottom = $bandTop - $bandHeight;
            $slice = array_slice($words, $band * $perBand, $perBand);
            $text = $slice === [] ? (string) $box['text'] : implode(' ', $slice);

            $result[] = [
                'text' => $text,
                'x_start' => (float) $box['x_start'],
                'x_end' => (float) $box['x_end'],
                'top' => $bandTop,
                'bottom' => $bandBottom,
                'height' => max(0.1, $bandHeight),
                'confidence' => (float) $box['confidence'],
            ];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rowBoxes
     * @return array{text: string, x_start: float, x_end: float, top: float, bottom: float, height: float, y: float, confidence: float}|null
     */
    private function mergeRow(array $rowBoxes, float $baselineRatio): ?array
    {
        if ($rowBoxes === []) {
            return null;
        }

        // Left-to-right reading order within the row.
        usort($rowBoxes, static fn (array $a, array $b): int => ((float) $a['x_start']) <=> ((float) $b['x_start']));

        $text = trim(implode(' ', array_map(static fn (array $b): string => (string) $b['text'], $rowBoxes)));
        if ($text === '') {
            return null;
        }

        $top = max(array_map(static fn (array $b): float => (float) $b['top'], $rowBoxes));
        $bottom = min(array_map(static fn (array $b): float => (float) $b['bottom'], $rowBoxes));
        $xStart = min(array_map(static fn (array $b): float => (float) $b['x_start'], $rowBoxes));
        $xEnd = max(array_map(static fn (array $b): float => (float) $b['x_end'], $rowBoxes));
        $height = max(0.1, $top - $bottom);

        // Confidence weighted by fragment character length (longer fragments are
        // more informative), so a stray high-confidence single glyph cannot
        // dominate the row score.
        $weightTotal = 0.0;
        $confidenceTotal = 0.0;
        foreach ($rowBoxes as $b) {
            $weight = max(1, $this->stringLength((string) $b['text']));
            $weightTotal += $weight;
            $confidenceTotal += $weight * (float) $b['confidence'];
        }
        $confidence = $weightTotal > 0 ? $confidenceTotal / $weightTotal : 0.0;

        return [
            'text' => $text,
            'x_start' => $xStart,
            'x_end' => $xEnd,
            'top' => $top,
            'bottom' => $bottom,
            'height' => $height,
            'y' => $bottom + ($height * $baselineRatio),
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $boxes
     */
    private function medianHeight(array $boxes): float
    {
        $heights = array_values(array_filter(
            array_map(static fn (array $box): float => (float) $box['height'], $boxes),
            static fn (float $height): bool => $height > 0.0
        ));

        if ($heights === []) {
            return 0.0;
        }

        sort($heights, SORT_NUMERIC);
        $count = count($heights);
        $mid = intdiv($count, 2);

        return $count % 2 === 0
            ? ($heights[$mid - 1] + $heights[$mid]) / 2
            : $heights[$mid];
    }

    private function rowClusterEnabled(array $options): bool
    {
        if (array_key_exists('row_cluster_enabled', $options)) {
            return (bool) $options['row_cluster_enabled'];
        }

        return (bool) config('paddleocr.row_cluster_enabled', true);
    }

    private function splitLineIntoWords(array $line, int $pageNo, int $wordIndexOffset): array
    {
        $text = $line['text'];
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || count($words) === 0) {
            return [];
        }

        $lineXStart = (float) $line['x_start'];
        $lineXEnd = (float) $line['x_end'];
        $lineWidth = $lineXEnd - $lineXStart;
        $totalChars = mb_strlen($text);
        if ($totalChars === 0) {
            return [];
        }

        $charWidth = $lineWidth / $totalChars;
        $result = [];
        $currentOffset = 0;

        foreach ($words as $idx => $word) {
            $wordLen = mb_strlen($word);
            $pos = mb_strpos($text, $word, $currentOffset);
            if ($pos === false) {
                $pos = $currentOffset;
            }

            $wordXStart = $lineXStart + ($pos * $charWidth);
            $wordXEnd = $wordXStart + ($wordLen * $charWidth);

            $result[] = [
                'id' => "p{$pageNo}-word-" . ($wordIndexOffset + $idx),
                'text' => $word,
                'x_start' => round($wordXStart, 3),
                'x_end' => round($wordXEnd, 3),
                'y' => $line['y'],
                'top' => $line['top'],
                'bottom' => $line['bottom'],
                'height' => $line['height'],
                'confidence' => $line['confidence'],
                'source' => 'paddleocr',
            ];

            $currentOffset = $pos + $wordLen;
        }

        return $result;
    }

    private function normalizeText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', $text);
        return trim((string) $normalized);
    }

    private function stringLength(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }
}

<?php

namespace App\Services;

class PdfLineConfidenceScorer
{
    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $layout
     * @param  array<string, string>  $artifactMarks
     * @return array<string, mixed>
     */
    public function scorePage(array $page, array $layout, array $artifactMarks = []): array
    {
        $lines = array_values(array_filter(
            is_array($page['raw_lines'] ?? null) ? $page['raw_lines'] : [],
            static fn (mixed $line): bool => is_array($line)
        ));
        usort($lines, static fn (array $a, array $b): int => ((float) ($b['y'] ?? 0.0)) <=> ((float) ($a['y'] ?? 0.0)));

        $minimumLineConfidence = max(0.10, min(0.95, (float) config('line_numbering.minimum_line_confidence', 0.48)));
        $pageConfidenceFloor = max(0.10, min(0.95, (float) config('line_numbering.minimum_page_confidence', 0.58)));
        $inlineClauseMarkerIds = $this->inlineClauseMarkerLineIds($lines, $layout);
        $tableRowLineIds = array_fill_keys(array_values(array_filter(
            is_array($layout['table_row_line_ids'] ?? null) ? $layout['table_row_line_ids'] : [],
            static fn (mixed $lineId): bool => is_string($lineId) && trim($lineId) !== ''
        )), true);
        $tableRowAnchors = array_values(array_filter(
            is_array($layout['table_row_anchors'] ?? null) ? $layout['table_row_anchors'] : [],
            static fn (mixed $anchor): bool => is_array($anchor)
        ));
        $bodyRegion = is_array($layout['body_region'] ?? null) ? $layout['body_region'] : [
            'left' => 0.0,
            'right' => (float) ($layout['page_width'] ?? 0.0),
            'top' => (float) ($layout['page_height'] ?? 0.0),
            'bottom' => 0.0,
        ];
        $medianSpacing = max(0.0, (float) ($layout['median_line_spacing'] ?? 0.0));

        // On OCR pages, dropping a genuine body line for low confidence shifts
        // every ordinal number below it. Keep low-confidence lines that still
        // sit inside the body region so the running line count stays honest.
        $keepLowConfidenceBodyLines = ($page['engine'] ?? null) === 'ocr'
            && (bool) config('line_numbering.ocr_keep_low_confidence_body_lines', true);

        $trustedAnchors = [];
        $trustedLines = [];
        $scoredLines = [];
        $suppressedCounts = [
            'header' => 0,
            'footer' => 0,
            'structured_content' => 0,
            'outside_body_region' => 0,
            'low_confidence' => 0,
        ];

        foreach ($lines as $index => $line) {
            $previous = $lines[$index - 1] ?? null;
            $next = $lines[$index + 1] ?? null;
            $lineId = (string) ($line['id'] ?? '');
            $artifact = $lineId !== '' ? ($artifactMarks[$lineId] ?? null) : null;

            $scored = $this->scoreLine(
                $line,
                $previous,
                $next,
                $bodyRegion,
                $layout,
                $artifact,
                $medianSpacing,
                isset($tableRowLineIds[$lineId]),
                isset($inlineClauseMarkerIds[$lineId])
            );

            $scoredLines[] = $scored;

            if (($scored['suppressed_reason'] ?? null) !== null) {
                $suppressedReason = (string) $scored['suppressed_reason'];
                if (isset($suppressedCounts[$suppressedReason])) {
                    $suppressedCounts[$suppressedReason]++;
                }
                continue;
            }

            if ((float) $scored['confidence'] < $minimumLineConfidence) {
                if (! ($keepLowConfidenceBodyLines && ($scored['inside_body_region'] ?? false))) {
                    $suppressedCounts['low_confidence']++;
                    $scoredLines[array_key_last($scoredLines)]['suppressed_reason'] = 'low_confidence';
                    continue;
                }

                $scoredLines[array_key_last($scoredLines)]['retained_low_confidence'] = true;
            }

            $trustedAnchors[] = [
                'y' => (float) $scored['y'],
                'x_start' => (float) $scored['x_start'],
                'x_end' => (float) $scored['x_end'],
            ];
            $trustedLines[] = $scored;
        }

        if ($tableRowAnchors !== []) {
            foreach ($tableRowAnchors as $rowIndex => $rowAnchor) {
                $rowY = (float) ($rowAnchor['y'] ?? 0.0);
                if ($this->hasNearbyAnchor($trustedAnchors, $rowY)) {
                    continue;
                }

                $anchor = [
                    'y' => $rowY,
                    'x_start' => (float) ($rowAnchor['x_start'] ?? 0.0),
                    'x_end' => (float) ($rowAnchor['x_end'] ?? 0.0),
                ];
                $trustedAnchors[] = $anchor;
                $trustedLines[] = [
                    'id' => (string) ($rowAnchor['id'] ?? ('table-row-' . ($rowIndex + 1))),
                    'text' => (string) ($rowAnchor['text'] ?? ''),
                    'x_start' => $anchor['x_start'],
                    'x_end' => $anchor['x_end'],
                    'y' => $anchor['y'],
                    'confidence' => 0.78,
                    'spacing_consistency' => 0.6,
                    'inside_body_region' => true,
                    'horizontal_overlap_ratio' => 1.0,
                    'suppressed_reason' => null,
                    'region' => 'table',
                ];
            }

            usort($trustedAnchors, static fn (array $a, array $b): int => ((float) ($b['y'] ?? 0.0)) <=> ((float) ($a['y'] ?? 0.0)));
            usort($trustedLines, static fn (array $a, array $b): int => ((float) ($b['y'] ?? 0.0)) <=> ((float) ($a['y'] ?? 0.0)));
        }

        $pageConfidence = $this->pageConfidence($page, $layout, $trustedLines, $lines, $pageConfidenceFloor);

        return [
            'trusted_anchors' => $trustedAnchors,
            'trusted_lines' => $trustedLines,
            'scored_lines' => $scoredLines,
            'suppressed_counts' => $suppressedCounts,
            'page_confidence' => $pageConfidence['score'],
            'page_confidence_label' => $pageConfidence['label'],
            'low_confidence_reason' => $pageConfidence['reason'],
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $next
     * @param  array<string, float>  $bodyRegion
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    private function scoreLine(
        array $line,
        ?array $previous,
        ?array $next,
        array $bodyRegion,
        array $layout,
        ?string $artifact,
        float $medianSpacing,
        bool $belongsToTableRow,
        bool $isInlineClauseMarker
    ): array {
        $lineWidth = max(0.0, ((float) ($line['x_end'] ?? 0.0)) - ((float) ($line['x_start'] ?? 0.0)));
        $lineCenter = (((float) ($line['x_start'] ?? 0.0)) + ((float) ($line['x_end'] ?? 0.0))) / 2;
        $bodyWidth = max(1.0, ((float) ($bodyRegion['right'] ?? 0.0)) - ((float) ($bodyRegion['left'] ?? 0.0)));
        $charCount = (int) ($line['char_count'] ?? 0);
        $words = is_array($line['words'] ?? null) ? $line['words'] : [];
        $alphaCount = preg_match_all('/\p{L}/u', (string) ($line['text'] ?? ''));

        $spacingConsistency = $this->spacingConsistency($line, $previous, $next, $medianSpacing);
        $insideVerticalBody = ((float) ($line['y'] ?? 0.0)) <= ((float) ($bodyRegion['top'] ?? 0.0) + 4.0)
            && ((float) ($line['y'] ?? 0.0)) >= ((float) ($bodyRegion['bottom'] ?? 0.0) - 4.0);
        $horizontalOverlap = $this->horizontalOverlapRatio($line, $bodyRegion);
        $insideHorizontalBody = $horizontalOverlap >= 0.32
            || ($lineCenter >= ((float) ($bodyRegion['left'] ?? 0.0)) && $lineCenter <= ((float) ($bodyRegion['right'] ?? 0.0)));
        $insideBody = $insideVerticalBody && $insideHorizontalBody;

        $suppressedReason = null;
        $score = 0.42;

        if ($artifact === 'header' || $artifact === 'footer') {
            $suppressedReason = $artifact;
            $score = 0.0;
        } else {
            $score += $insideBody ? 0.22 : -0.16;
            $score += $lineWidth >= max(24.0, $bodyWidth * 0.25) ? 0.12 : -0.05;
            $score += $charCount >= 10 ? 0.10 : ($charCount <= 2 ? -0.08 : 0.02);
            $score += $alphaCount > 0 ? 0.05 : -0.08;
            $score += count($words) >= 2 ? 0.05 : 0.0;
            $score += ($spacingConsistency - 0.5) * 0.20;

            $dominantColumn = is_array($layout['dominant_column'] ?? null) ? $layout['dominant_column'] : null;
            $outsideDominantColumn = $dominantColumn !== null
                && ($lineCenter < ((float) ($dominantColumn['left'] ?? 0.0)) || $lineCenter > ((float) ($dominantColumn['right'] ?? 0.0)));

            if ($isInlineClauseMarker) {
                $score -= 0.20;
                $suppressedReason = 'structured_content';
            } elseif ($belongsToTableRow) {
                $score -= 0.18;
                $suppressedReason = 'structured_content';
            } elseif ((bool) ($layout['multi_column_suspected'] ?? false) && $outsideDominantColumn) {
                $score -= 0.20;
                $suppressedReason = 'structured_content';
            }

            if ((bool) ($layout['table_suspected'] ?? false) && $this->isTableLike($line, (float) ($layout['page_width'] ?? 0.0))) {
                $score -= 0.18;
                $suppressedReason = 'structured_content';
            }

            if (! $insideBody && $suppressedReason === null && $score < 0.55) {
                $suppressedReason = 'outside_body_region';
            }
        }

        return $line + [
            'confidence' => max(0.0, min(1.0, round($score, 4))),
            'spacing_consistency' => round($spacingConsistency, 4),
            'inside_body_region' => $insideBody,
            'horizontal_overlap_ratio' => round($horizontalOverlap, 4),
            'suppressed_reason' => $suppressedReason,
            'region' => $insideBody ? 'body' : ($artifact ?? 'fringe'),
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $layout
     * @param  list<array<string, mixed>>  $trustedLines
     * @param  list<array<string, mixed>>  $rawLines
     * @return array{score: float, label: string, reason: string|null}
     */
    private function pageConfidence(array $page, array $layout, array $trustedLines, array $rawLines, float $pageConfidenceFloor): array
    {
        $rawCount = count($rawLines);
        if ($rawCount === 0) {
            return [
                'score' => 0.0,
                'label' => 'low',
                'reason' => 'no_extractable_lines',
            ];
        }

        $trustedCount = count($trustedLines);
        $averageConfidence = $trustedCount > 0
            ? array_sum(array_map(static fn (array $line): float => (float) ($line['confidence'] ?? 0.0), $trustedLines)) / $trustedCount
            : 0.0;
        $averageSpacingConsistency = $trustedCount > 0
            ? array_sum(array_map(static fn (array $line): float => (float) ($line['spacing_consistency'] ?? 0.0), $trustedLines)) / $trustedCount
            : 0.0;
        $trustedRatio = $trustedCount / $rawCount;

        $score = 0.15
            + (0.35 * $averageConfidence)
            + (0.20 * min($trustedRatio / 0.75, 1.0))
            + (0.15 * min($trustedCount / 18.0, 1.0))
            + (0.15 * $averageSpacingConsistency);

        if ((bool) ($layout['multi_column_suspected'] ?? false)) {
            $score -= 0.08;
        }

        if ((bool) ($layout['table_suspected'] ?? false)) {
            $score -= 0.03;
        }

        if (((int) ($page['page_rotation'] ?? 0)) !== 0) {
            $score -= 0.05;
        }

        if (($page['engine'] ?? '') === 'ocr') {
            $score -= 0.03;
        }

        $score = max(0.0, min(1.0, round($score, 4)));

        if ($score >= max(0.75, $pageConfidenceFloor + 0.12)) {
            return [
                'score' => $score,
                'label' => 'high',
                'reason' => null,
            ];
        }

        if ($score >= $pageConfidenceFloor) {
            return [
                'score' => $score,
                'label' => 'medium',
                'reason' => null,
            ];
        }

        $reason = $trustedCount === 0
            ? 'no_trusted_body_lines'
            : ($trustedRatio < 0.35 ? 'too_many_non_body_lines' : 'layout_is_ambiguous');

        return [
            'score' => $score,
            'label' => 'low',
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $bodyRegion
     */
    private function horizontalOverlapRatio(array $line, array $bodyRegion): float
    {
        $lineStart = (float) ($line['x_start'] ?? 0.0);
        $lineEnd = (float) ($line['x_end'] ?? 0.0);
        $lineWidth = max(1.0, $lineEnd - $lineStart);
        $overlap = max(
            0.0,
            min($lineEnd, (float) ($bodyRegion['right'] ?? 0.0)) - max($lineStart, (float) ($bodyRegion['left'] ?? 0.0))
        );

        return $overlap / $lineWidth;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $next
     */
    private function spacingConsistency(array $line, ?array $previous, ?array $next, float $medianSpacing): float
    {
        if ($medianSpacing <= 0.0) {
            return 0.5;
        }

        $scores = [];
        foreach ([$previous, $next] as $neighbor) {
            if (! is_array($neighbor)) {
                continue;
            }

            $delta = abs(((float) ($neighbor['y'] ?? 0.0)) - ((float) ($line['y'] ?? 0.0)));
            if ($delta <= 0.0) {
                continue;
            }

            $ratio = $delta / $medianSpacing;
            if ($ratio >= 0.6 && $ratio <= 1.4) {
                $scores[] = 1.0;
                continue;
            }

            if ($ratio >= 0.4 && $ratio <= 1.8) {
                $scores[] = 0.7;
                continue;
            }

            if ($ratio >= 0.25 && $ratio <= 2.2) {
                $scores[] = 0.4;
                continue;
            }

            $scores[] = 0.0;
        }

        if ($scores === []) {
            return 0.5;
        }

        return array_sum($scores) / count($scores);
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function isTableLike(array $line, float $pageWidth): bool
    {
        $words = is_array($line['words'] ?? null) ? $line['words'] : [];
        if (count($words) < 3) {
            return false;
        }

        usort($words, static fn (array $a, array $b): int => ((float) ($a['x_start'] ?? 0.0)) <=> ((float) ($b['x_start'] ?? 0.0)));
        $gapThreshold = max(24.0, $pageWidth * 0.035);
        $wideGaps = 0;

        for ($index = 0, $max = count($words) - 1; $index < $max; $index++) {
            $gap = ((float) ($words[$index + 1]['x_start'] ?? 0.0)) - ((float) ($words[$index]['x_end'] ?? 0.0));
            if ($gap >= $gapThreshold) {
                $wideGaps++;
            }
        }

        return $wideGaps >= 2;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, bool>
     */
    private function inlineClauseMarkerLineIds(array $lines, array $layout): array
    {
        if ((bool) ($layout['multi_column_suspected'] ?? false) || (bool) ($layout['table_suspected'] ?? false)) {
            return [];
        }

        $pageWidth = max(1.0, (float) ($layout['page_width'] ?? 0.0));
        $lineHeights = array_map(static fn (array $line): float => max(1.0, (float) ($line['height'] ?? 0.0)), $lines);
        $baselineTolerance = max(2.0, min(5.0, $this->median($lineHeights) * 0.28));
        $markerIds = [];

        foreach ($lines as $line) {
            $lineId = trim((string) ($line['id'] ?? ''));
            if ($lineId === '' || ! $this->looksLikeInlineClauseMarker($line, $pageWidth)) {
                continue;
            }

            foreach ($lines as $peer) {
                if ($peer === $line) {
                    continue;
                }

                $peerY = (float) ($peer['y'] ?? 0.0);
                $lineY = (float) ($line['y'] ?? 0.0);
                if (abs($peerY - $lineY) > $baselineTolerance) {
                    continue;
                }

                if ((float) ($peer['x_start'] ?? 0.0) <= ((float) ($line['x_end'] ?? 0.0) + 10.0)) {
                    continue;
                }

                if (! $this->isSubstantialBodyLine($peer, $pageWidth)) {
                    continue;
                }

                $markerIds[$lineId] = true;
                break;
            }
        }

        return $markerIds;
    }

    private function looksLikeInlineClauseMarker(array $line, float $pageWidth): bool
    {
        $text = trim((string) ($line['text'] ?? ''));
        if ($text === '' || preg_match('/^\d{1,3}(?:[.,]\d{1,3})*[.,)]?$/', $text) !== 1) {
            return false;
        }

        $width = max(0.0, ((float) ($line['x_end'] ?? 0.0)) - ((float) ($line['x_start'] ?? 0.0)));
        $charCount = (int) ($line['char_count'] ?? mb_strlen($text));
        $words = is_array($line['words'] ?? null) ? $line['words'] : [];

        if ($width > max(42.0, $pageWidth * 0.13)) {
            return false;
        }

        if ($charCount > 10 || count($words) > 2) {
            return false;
        }

        return true;
    }

    private function isSubstantialBodyLine(array $line, float $pageWidth): bool
    {
        $text = trim((string) ($line['text'] ?? ''));
        $width = max(0.0, ((float) ($line['x_end'] ?? 0.0)) - ((float) ($line['x_start'] ?? 0.0)));
        $charCount = (int) ($line['char_count'] ?? mb_strlen($text));
        $alphaCount = preg_match_all('/\p{L}/u', $text);

        return $width >= max(120.0, $pageWidth * 0.28)
            && $charCount >= 12
            && $alphaCount >= 4;
    }

    /**
     * @param  list<array{y: float, x_start: float, x_end: float}>  $trustedAnchors
     */
    private function hasNearbyAnchor(array $trustedAnchors, float $y): bool
    {
        foreach ($trustedAnchors as $anchor) {
            if (abs(((float) ($anchor['y'] ?? 0.0)) - $y) <= 4.0) {
                return true;
            }
        }

        return false;
    }

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

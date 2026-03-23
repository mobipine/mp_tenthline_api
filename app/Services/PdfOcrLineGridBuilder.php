<?php

namespace App\Services;

class PdfOcrLineGridBuilder
{
    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $layout
     * @param  list<array<string, mixed>>  $trustedLines
     * @param  list<array{y: float, x_start: float, x_end: float}>  $trustedAnchors
     * @return array{anchors: list<array{y: float, x_start: float, x_end: float}>, diagnostics: array<string, mixed>}|null
     */
    public function build(array $page, array $layout, array $trustedLines, array $trustedAnchors): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        if (($page['engine'] ?? null) !== 'ocr') {
            return null;
        }

        if ((bool) ($layout['multi_column_suspected'] ?? false) || (bool) ($layout['table_suspected'] ?? false)) {
            return null;
        }

        if (count($trustedLines) < $this->minimumTrustedLines()) {
            return null;
        }

        $bodyRegion = is_array($layout['body_region'] ?? null) ? $layout['body_region'] : null;
        if ($bodyRegion === null) {
            return null;
        }

        $spacing = $this->estimateBaseSpacing($trustedLines, (float) ($layout['median_line_spacing'] ?? 0.0));
        if ($spacing === null) {
            return null;
        }

        $phase = $this->estimatePhase($trustedLines, $spacing);
        $top = (float) ($bodyRegion['top'] ?? 0.0);
        $bottom = (float) ($bodyRegion['bottom'] ?? 0.0);
        if ($top <= $bottom) {
            return null;
        }

        $topBaseline = $top - $this->positiveMod($top - $phase, $spacing);
        $gridAnchors = [];
        $usedTrustedIndexes = [];
        $snapTolerance = $spacing * $this->snapToleranceFactor();

        $medianXStart = $this->median(array_map(
            static fn (array $line): float => (float) ($line['x_start'] ?? 0.0),
            $trustedLines
        ));
        $medianXEnd = $this->median(array_map(
            static fn (array $line): float => (float) ($line['x_end'] ?? 0.0),
            $trustedLines
        ));

        for ($y = $topBaseline; $y >= $bottom; $y -= $spacing) {
            $nearest = $this->nearestTrustedLine($trustedLines, $usedTrustedIndexes, $y, $snapTolerance);

            if ($nearest !== null) {
                $trustedLine = $trustedLines[$nearest];
                $usedTrustedIndexes[$nearest] = true;
                $gridAnchors[] = [
                    'y' => (float) $trustedLine['y'],
                    'x_start' => (float) $trustedLine['x_start'],
                    'x_end' => (float) $trustedLine['x_end'],
                ];

                continue;
            }

            $gridAnchors[] = [
                'y' => round($y, 3),
                'x_start' => round($medianXStart, 3),
                'x_end' => round($medianXEnd, 3),
            ];
        }

        if ($gridAnchors === []) {
            return null;
        }

        $estimatedCount = count($gridAnchors);
        $trustedCount = count($trustedAnchors);
        if ($trustedCount > 0 && ($estimatedCount / $trustedCount) > $this->maximumExpansionFactor()) {
            return null;
        }

        if ($estimatedCount <= $trustedCount) {
            return null;
        }

        return [
            'anchors' => $gridAnchors,
            'diagnostics' => [
                'mode' => 'ocr_grid_reconstruction',
                'spacing_pt' => round($spacing, 3),
                'phase' => round($phase, 3),
                'estimated_anchor_count' => $estimatedCount,
                'trusted_anchor_count' => $trustedCount,
                'grid_top_baseline' => round($topBaseline, 3),
                'snap_tolerance_pt' => round($snapTolerance, 3),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $trustedLines
     */
    private function estimateBaseSpacing(array $trustedLines, float $layoutSpacing): ?float
    {
        $deltas = [];
        $ys = array_map(static fn (array $line): float => (float) ($line['y'] ?? 0.0), $trustedLines);
        rsort($ys, SORT_NUMERIC);

        for ($index = 0, $max = count($ys) - 1; $index < $max; $index++) {
            $delta = $ys[$index] - $ys[$index + 1];
            if ($delta > 0.5) {
                $deltas[] = $delta;
            }
        }

        if ($deltas === []) {
            return null;
        }

        $candidates = [];
        $minSpacing = $this->minimumSpacing();
        $maxSpacing = $this->maximumSpacing();

        if ($layoutSpacing >= $minSpacing && $layoutSpacing <= $maxSpacing) {
            $candidates[] = $layoutSpacing;
        }

        foreach ($deltas as $delta) {
            for ($multiple = 1; $multiple <= 4; $multiple++) {
                $candidate = $delta / $multiple;
                if ($candidate >= $minSpacing && $candidate <= $maxSpacing) {
                    $candidates[] = round($candidate, 3);
                }
            }
        }

        $candidates = array_values(array_unique($candidates));
        if ($candidates === []) {
            return null;
        }

        $best = null;
        foreach ($candidates as $candidate) {
            $score = $this->candidateScore($deltas, $candidate);
            if ($best === null
                || $score['support'] > $best['support']
                || ($score['support'] === $best['support'] && $score['error'] < $best['error'])) {
                $best = $score + ['spacing' => $candidate];
            }
        }

        if ($best === null || $best['support'] < $this->minimumRegularSupport()) {
            return null;
        }

        return (float) $best['spacing'];
    }

    /**
     * @param  list<float>  $deltas
     * @return array{support: float, error: float}
     */
    private function candidateScore(array $deltas, float $candidate): array
    {
        $matches = 0;
        $totalError = 0.0;
        $tolerance = max(1.4, $candidate * 0.22);

        foreach ($deltas as $delta) {
            $multiple = max(1, min(6, (int) round($delta / $candidate)));
            $expected = $candidate * $multiple;
            $error = abs($delta - $expected);
            if ($error <= $tolerance) {
                $matches++;
                $totalError += $error;
            }
        }

        $support = $matches / max(1, count($deltas));
        $averageError = $matches > 0 ? $totalError / $matches : INF;

        return [
            'support' => round($support, 4),
            'error' => round((float) $averageError, 4),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $trustedLines
     */
    private function estimatePhase(array $trustedLines, float $spacing): float
    {
        $residues = array_map(fn (array $line): float => $this->positiveMod((float) ($line['y'] ?? 0.0), $spacing), $trustedLines);

        $bestPhase = $residues[0] ?? 0.0;
        $bestScore = INF;

        foreach ($residues as $candidate) {
            $score = 0.0;
            foreach ($residues as $residue) {
                $delta = abs($residue - $candidate);
                $score += min($delta, $spacing - $delta);
            }

            if ($score < $bestScore) {
                $bestScore = $score;
                $bestPhase = $candidate;
            }
        }

        return $bestPhase;
    }

    /**
     * @param  list<array<string, mixed>>  $trustedLines
     * @param  array<int, bool>  $usedTrustedIndexes
     */
    private function nearestTrustedLine(array $trustedLines, array $usedTrustedIndexes, float $gridY, float $tolerance): ?int
    {
        $bestIndex = null;
        $bestDistance = INF;

        foreach ($trustedLines as $index => $line) {
            if (isset($usedTrustedIndexes[$index])) {
                continue;
            }

            $distance = abs(((float) ($line['y'] ?? 0.0)) - $gridY);
            if ($distance <= $tolerance && $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private function enabled(): bool
    {
        return (bool) config('line_numbering.enable_ocr_grid_reconstruction', true);
    }

    private function minimumTrustedLines(): int
    {
        return max(4, (int) config('line_numbering.ocr_grid_min_trusted_lines', 6));
    }

    private function minimumSpacing(): float
    {
        return max(6.0, (float) config('line_numbering.ocr_grid_min_spacing_pt', 10.0));
    }

    private function maximumSpacing(): float
    {
        return max($this->minimumSpacing() + 1.0, (float) config('line_numbering.ocr_grid_max_spacing_pt', 36.0));
    }

    private function minimumRegularSupport(): float
    {
        return max(0.30, min(1.0, (float) config('line_numbering.ocr_grid_min_regular_support', 0.6)));
    }

    private function snapToleranceFactor(): float
    {
        return max(0.10, min(0.80, (float) config('line_numbering.ocr_grid_snap_tolerance_factor', 0.38)));
    }

    private function maximumExpansionFactor(): float
    {
        return max(1.05, (float) config('line_numbering.ocr_grid_max_expansion_factor', 2.4));
    }

    private function positiveMod(float $value, float $modulus): float
    {
        if ($modulus <= 0.0) {
            return 0.0;
        }

        $result = fmod($value, $modulus);

        return $result < 0 ? $result + $modulus : $result;
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

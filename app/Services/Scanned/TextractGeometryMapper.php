<?php

namespace App\Services\Scanned;

class TextractGeometryMapper
{
    /**
     * @param  array<string, mixed>  $boundingBox
     * @return array{x_start: float, x_end: float, top: float, bottom: float, height: float, y: float}
     */
    public function mapBoundingBox(array $boundingBox, float $pageWidth, float $pageHeight, ?float $baselineRatio = null): array
    {
        $pageWidth = max(1.0, $pageWidth);
        $pageHeight = max(1.0, $pageHeight);

        $left = $this->normalizedValue($boundingBox['Left'] ?? 0.0);
        $top = $this->normalizedValue($boundingBox['Top'] ?? 0.0);
        $width = $this->normalizedValue($boundingBox['Width'] ?? 0.0);
        $height = $this->normalizedValue($boundingBox['Height'] ?? 0.0);

        $xStart = round($left * $pageWidth, 3);
        $xEnd = round(($left + $width) * $pageWidth, 3);
        $topPt = round($pageHeight - ($top * $pageHeight), 3);
        $bottomPt = round($pageHeight - (($top + $height) * $pageHeight), 3);
        $heightPt = round(max(0.0, $topPt - $bottomPt), 3);
        $resolvedBaselineRatio = $baselineRatio ?? (float) config('textract.baseline_ratio', 0.82);
        $resolvedBaselineRatio = max(0.0, min(1.0, $resolvedBaselineRatio));

        return [
            'x_start' => $xStart,
            'x_end' => $xEnd,
            'top' => $topPt,
            'bottom' => $bottomPt,
            'height' => $heightPt,
            'y' => round($bottomPt + ($heightPt * $resolvedBaselineRatio), 3),
        ];
    }

    private function normalizedValue(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Extracts text line positions from a PDF so numbers can be placed on the 10th, 20th, etc. line.
 * Uses getDataTm() to get (x,y) per text segment, groups by Y to form lines, returns Y positions
 * in PDF coordinates (origin bottom-left).
 */
class PdfLineExtractor
{
    /** Y tolerance (points): segments within this are considered the same line. */
    private const LINE_Y_TOLERANCE_PT = 3;

    /** Approximate glyph width ratio for width estimation in points. */
    private const GLYPH_WIDTH_FACTOR = 0.52;

    /**
     * Returns per-page arrays of line anchors with geometry in PDF coords.
     *
     * @return array<int, array<int, array{y: float, x_start: float, x_end: float}>>
     */
    public function getLineAnchorsPerPage(string $inputPath): array
    {
        try {
            $config = new Config();
            $config->setDataTmFontInfoHasToBeIncluded(true);

            $parser = new Parser([], $config);
            $pdf = $parser->parseFile($inputPath);
        } catch (\Throwable $e) {
            Log::warning('PdfLineExtractor: Parse failed, returning no lines', [
                'path' => $inputPath,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $pages = $pdf->getPages();
        $result = [];
        $totalLines = 0;

        foreach ($pages as $index => $page) {
            $pageNo = $index + 1;
            $lineAnchors = $this->getLineAnchorsForPage($page);

            if ($lineAnchors !== []) {
                $result[$pageNo] = $lineAnchors;
                $totalLines += count($lineAnchors);
            }
        }

        Log::info('PdfLineExtractor: Line anchor extraction complete', [
            'path' => $inputPath,
            'pages_with_data' => count($result),
            'total_lines_detected' => $totalLines,
            'lines_per_page' => array_map('count', $result),
        ]);

        return $result;
    }

    /**
     * Returns per-page arrays of line Y positions (PDF coords, from bottom).
     * Lines are ordered top-to-bottom (first line of page = index 0).
     * Each value is the representative Y for that line (e.g. baseline).
     *
     * @return array<int, array<int, float>> Page number (1-based) => list of Y positions (top to bottom)
     */
    public function getLineYPositionsPerPage(string $inputPath): array
    {
        $result = [];
        $anchorsPerPage = $this->getLineAnchorsPerPage($inputPath);

        foreach ($anchorsPerPage as $pageNo => $lineAnchors) {
            $result[$pageNo] = array_map(
                static fn (array $line): float => $line['y'],
                $lineAnchors
            );
        }

        return $result;
    }

    /**
     * Get ordered list of line anchors for one page (PDF coords, from bottom).
     * Lines ordered top-to-bottom (high Y first).
     *
     * @return list<array{y: float, x_start: float, x_end: float}>
     */
    private function getLineAnchorsForPage($page): array
    {
        $data = $page->getDataTm();
        if (! is_array($data) || $data === []) {
            return [];
        }

        $segments = [];
        foreach ($data as $entry) {
            if (! is_array($entry) || ! isset($entry[0][4], $entry[0][5])) {
                continue;
            }

            $x = (float) $entry[0][4];
            $y = (float) $entry[0][5];

            $text = isset($entry[1]) && is_string($entry[1]) ? $entry[1] : '';
            $fontSize = isset($entry[3]) && is_numeric($entry[3]) ? (float) $entry[3] : 9.0;
            $estimatedWidth = $this->estimateTextWidthPt($text, $fontSize);

            $segments[] = [
                'x_start' => $x,
                'x_end' => $x + $estimatedWidth,
                'y' => $y,
            ];
        }

        if ($segments === []) {
            return [];
        }

        // Sort by Y descending (top of page first), then X ascending
        usort($segments, function ($a, $b) {
            $dy = $b['y'] - $a['y'];
            if (abs($dy) > self::LINE_Y_TOLERANCE_PT) {
                return $dy > 0 ? 1 : -1;
            }

            return $a['x_start'] <=> $b['x_start'];
        });

        // Group segments into lines by Y (within tolerance)
        $lines = [];
        $currentLineY = null;
        $currentLineSegments = [];

        foreach ($segments as $seg) {
            if ($currentLineY === null || abs($seg['y'] - $currentLineY) > self::LINE_Y_TOLERANCE_PT) {
                if ($currentLineSegments !== []) {
                    $lines[] = $this->lineAnchor($currentLineSegments);
                }
                $currentLineY = $seg['y'];
                $currentLineSegments = [$seg];
            } else {
                $currentLineSegments[] = $seg;
            }
        }

        if ($currentLineSegments !== []) {
            $lines[] = $this->lineAnchor($currentLineSegments);
        }

        return $lines;
    }

    /**
     * Build line anchor from grouped segments.
     *
     * @param array<int, array{x_start: float, x_end: float, y: float}> $segments
     * @return array{y: float, x_start: float, x_end: float}
     */
    private function lineAnchor(array $segments): array
    {
        $sumY = 0.0;
        $xStart = INF;
        $xEnd = -INF;
        $count = count($segments);

        foreach ($segments as $segment) {
            $sumY += $segment['y'];
            $xStart = min($xStart, $segment['x_start']);
            $xEnd = max($xEnd, $segment['x_end']);
        }

        return [
            'y' => $count > 0 ? $sumY / $count : 0.0,
            'x_start' => is_finite($xStart) ? $xStart : 0.0,
            'x_end' => is_finite($xEnd) ? $xEnd : 0.0,
        ];
    }

    private function estimateTextWidthPt(string $text, float $fontSize): float
    {
        $cleanText = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $length = $this->textLength($cleanText);

        if ($length <= 0) {
            return 0.0;
        }

        $effectiveFontSize = $fontSize > 0 ? $fontSize : 9.0;

        return $length * $effectiveFontSize * self::GLYPH_WIDTH_FACTOR;
    }

    private function textLength(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text);
        }

        return strlen($text);
    }
}

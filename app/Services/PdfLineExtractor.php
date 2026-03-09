<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

/**
 * Extracts text line positions from a PDF so numbers can be placed on the 10th, 20th, etc. line.
 * Uses getDataTm() to get (x,y) per text segment, groups by Y to form lines, returns Y positions
 * in PDF coordinates (origin bottom-left).
 */
class PdfLineExtractor
{
    private const DEFAULT_LINE_Y_TOLERANCE_PT = 3.0;
    private const MIN_LINE_Y_TOLERANCE_PT = 1.5;
    private const MAX_LINE_Y_TOLERANCE_PT = 8.0;
    private const ROTATION_HORIZONTAL_THRESHOLD_DEG = 8.0;
    private const DEFAULT_POPPLER_BASELINE_RATIO = 0.78;

    /** @var array<string, mixed> */
    private array $lastDiagnostics = [];

    /**
     * Returns per-page arrays of line anchors with geometry in PDF coords.
     *
     * @return array<int, array<int, array{y: float, x_start: float, x_end: float}>>
     */
    public function getLineAnchorsPerPage(string $inputPath): array
    {
        $enginePreference = strtolower((string) config('line_numbering.extractor_engine', 'auto'));
        $this->lastDiagnostics = [
            'engine_preference' => $enginePreference,
            'engine_used' => null,
            'fallback_to_smalot' => false,
            'pages' => [],
            'total_lines_detected' => 0,
            'input_path' => $inputPath,
        ];

        $lineAnchorsPerPage = [];

        if (in_array($enginePreference, ['auto', 'poppler'], true)) {
            $poppler = $this->extractUsingPoppler($inputPath);
            if ($poppler['success']) {
                $lineAnchorsPerPage = $poppler['anchors_per_page'];
                $this->lastDiagnostics['engine_used'] = 'poppler';
                $this->lastDiagnostics['pages'] = $poppler['page_diagnostics'];
                $this->lastDiagnostics['total_lines_detected'] = $poppler['total_lines_detected'];
            } elseif ($enginePreference === 'poppler') {
                Log::warning('[LegalLine] PdfLineExtractor: poppler extraction failed, falling back to smalot', [
                    'path' => $inputPath,
                    'reason' => $poppler['reason'] ?? 'unknown',
                ]);
                $this->lastDiagnostics['fallback_to_smalot'] = true;
            }
        }

        if ($lineAnchorsPerPage === []) {
            $smalot = $this->extractUsingSmalot($inputPath);
            $lineAnchorsPerPage = $smalot['anchors_per_page'];
            $this->lastDiagnostics['engine_used'] = 'smalot';
            $this->lastDiagnostics['pages'] = $smalot['page_diagnostics'];
            $this->lastDiagnostics['total_lines_detected'] = $smalot['total_lines_detected'];
            $this->lastDiagnostics['fallback_to_smalot'] = $this->lastDiagnostics['fallback_to_smalot'] || $enginePreference === 'auto';
        }

        Log::info('[LegalLine] PdfLineExtractor: extraction complete', [
            'path' => $inputPath,
            'engine_preference' => $this->lastDiagnostics['engine_preference'],
            'engine_used' => $this->lastDiagnostics['engine_used'],
            'fallback_to_smalot' => $this->lastDiagnostics['fallback_to_smalot'],
            'pages_with_data' => count($lineAnchorsPerPage),
            'total_lines_detected' => $this->lastDiagnostics['total_lines_detected'],
            'lines_per_page' => array_map('count', $lineAnchorsPerPage),
        ]);

        return $lineAnchorsPerPage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLastDiagnostics(): array
    {
        return $this->lastDiagnostics;
    }

    /**
     * @return array{success: bool, reason?: string, anchors_per_page: array<int, list<array{y: float, x_start: float, x_end: float}>>, page_diagnostics: array<int, array<string, mixed>>, total_lines_detected: int}
     */
    private function extractUsingPoppler(string $inputPath): array
    {
        $binary = (string) config('line_numbering.poppler_binary', 'pdftotext');
        if (! $this->commandExists($binary)) {
            return [
                'success' => false,
                'reason' => 'pdftotext_not_found',
                'anchors_per_page' => [],
                'page_diagnostics' => [],
                'total_lines_detected' => 0,
            ];
        }

        $command = sprintf(
            '%s -bbox-layout -enc UTF-8 %s - 2>/dev/null',
            escapeshellcmd($binary),
            escapeshellarg($inputPath)
        );

        $xml = shell_exec($command);
        if (! is_string($xml) || trim($xml) === '') {
            return [
                'success' => false,
                'reason' => 'empty_poppler_output',
                'anchors_per_page' => [],
                'page_diagnostics' => [],
                'total_lines_detected' => 0,
            ];
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        if (! $loaded) {
            // Fallback parser for environments where XHTML parsing as XML fails.
            $loaded = $dom->loadHTML($xml);
        }
        libxml_clear_errors();

        if (! $loaded) {
            return [
                'success' => false,
                'reason' => 'invalid_poppler_html',
                'anchors_per_page' => [],
                'page_diagnostics' => [],
                'total_lines_detected' => 0,
            ];
        }

        $xpath = new \DOMXPath($dom);
        $pageNodes = $xpath->query('//*[local-name()="page"]');
        if (! $pageNodes instanceof \DOMNodeList || $pageNodes->length === 0) {
            return [
                'success' => false,
                'reason' => 'no_page_nodes',
                'anchors_per_page' => [],
                'page_diagnostics' => [],
                'total_lines_detected' => 0,
            ];
        }

        $anchorsPerPage = [];
        $pageDiagnostics = [];
        $totalLines = 0;
        $baselineRatio = (float) config('line_numbering.poppler_baseline_ratio', self::DEFAULT_POPPLER_BASELINE_RATIO);
        $baselineRatio = max(0.60, min(0.90, $baselineRatio));

        foreach ($pageNodes as $index => $pageNode) {
            if (! $pageNode instanceof \DOMElement) {
                continue;
            }

            $pageNo = $index + 1;
            $pageHeight = $this->elementFloatAttr($pageNode, 'height');
            $lineNodes = $xpath->query('.//*[local-name()="line"]', $pageNode);

            $anchors = [];
            $skipped = 0;
            $lineNodeCount = $lineNodes instanceof \DOMNodeList ? $lineNodes->length : 0;

            if ($lineNodes instanceof \DOMNodeList) {
                foreach ($lineNodes as $lineNode) {
                    if (! $lineNode instanceof \DOMElement) {
                        continue;
                    }

                    $text = trim(preg_replace('/\s+/u', ' ', (string) $lineNode->textContent) ?? '');
                    if ($text === '') {
                        $skipped++;
                        continue;
                    }

                    $xMin = $this->elementFloatAttr($lineNode, 'xMin');
                    $xMax = $this->elementFloatAttr($lineNode, 'xMax');
                    $yMin = $this->elementFloatAttr($lineNode, 'yMin');
                    $yMax = $this->elementFloatAttr($lineNode, 'yMax');

                    if ($xMax <= $xMin || $yMax <= $yMin || $pageHeight <= 0) {
                        $skipped++;
                        continue;
                    }

                    $lineHeight = max(0.1, $yMax - $yMin);
                    $baselineFromTop = $yMin + ($lineHeight * $baselineRatio);
                    $yPdf = max(0.0, $pageHeight - $baselineFromTop);

                    $anchors[] = [
                        'y' => $yPdf,
                        'x_start' => $xMin,
                        'x_end' => $xMax,
                    ];
                }
            }

            $filteredOutlierLines = 0;
            $anchors = $this->sortAndFilterAnchors($anchors, $filteredOutlierLines);

            if ($anchors !== []) {
                $anchorsPerPage[$pageNo] = $anchors;
                $totalLines += count($anchors);
            }

            $pageDiagnostics[$pageNo] = [
                'engine' => 'poppler',
                'line_nodes' => $lineNodeCount,
                'lines_detected' => count($anchors),
                'empty_or_invalid_lines_skipped' => $skipped,
                'filtered_outlier_lines' => $filteredOutlierLines,
            ];
        }

        return [
            'success' => $anchorsPerPage !== [],
            'reason' => $anchorsPerPage !== [] ? null : 'no_lines_detected',
            'anchors_per_page' => $anchorsPerPage,
            'page_diagnostics' => $pageDiagnostics,
            'total_lines_detected' => $totalLines,
        ];
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
    private function getLineAnchorsForPage($page, int $pageNo, array &$pageDiagnostic): array
    {
        $data = $page->getDataTm();
        if (! is_array($data) || $data === []) {
            $pageDiagnostic = [
                'engine' => 'smalot',
                'segments_raw' => 0,
                'segments_kept' => 0,
                'segments_rotated_skipped' => 0,
                'adaptive_tolerance_pt' => $this->baseLineTolerancePt(),
                'lines_detected' => 0,
                'filtered_outlier_lines' => 0,
            ];
            return [];
        }

        $segments = [];
        $segmentsRaw = 0;
        $rotatedSkipped = 0;

        foreach ($data as $entry) {
            $segmentsRaw++;

            if (! is_array($entry) || ! isset($entry[0][4], $entry[0][5])) {
                continue;
            }

            $matrix = is_array($entry[0]) ? $entry[0] : [];
            $x = (float) $entry[0][4];
            $y = (float) $entry[0][5];
            $a = (float) ($matrix[0] ?? 1.0);
            $b = (float) ($matrix[1] ?? 0.0);
            $angleDeg = $this->normalizeAngle((float) rad2deg(atan2($b, $a)));

            if (! $this->isNearHorizontal($angleDeg)) {
                $rotatedSkipped++;
                continue;
            }

            $text = isset($entry[1]) && is_string($entry[1]) ? $entry[1] : '';
            $normalizedText = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($normalizedText === '') {
                continue;
            }

            $fontId = isset($entry[2]) ? strtolower((string) $entry[2]) : '';
            $fontSize = isset($entry[3]) && is_numeric($entry[3]) ? (float) $entry[3] : 9.0;
            $estimatedWidth = $this->estimateTextWidthPt($normalizedText, $fontSize, $fontId);
            $angleRad = deg2rad($angleDeg);
            $x2 = $x + ($estimatedWidth * cos($angleRad));

            $segments[] = [
                'x_start' => min($x, $x2),
                'x_end' => max($x, $x2),
                'y' => $y,
            ];
        }

        if ($segments === []) {
            $pageDiagnostic = [
                'engine' => 'smalot',
                'segments_raw' => $segmentsRaw,
                'segments_kept' => 0,
                'segments_rotated_skipped' => $rotatedSkipped,
                'adaptive_tolerance_pt' => $this->baseLineTolerancePt(),
                'lines_detected' => 0,
                'filtered_outlier_lines' => 0,
            ];
            return [];
        }

        $adaptiveTolerance = $this->resolveAdaptiveTolerance($segments);
        $lines = $this->groupSegmentsIntoLines($segments, $adaptiveTolerance);
        $filteredOutlierLines = 0;
        $lines = $this->sortAndFilterAnchors($lines, $filteredOutlierLines);

        $pageDiagnostic = [
            'engine' => 'smalot',
            'page' => $pageNo,
            'segments_raw' => $segmentsRaw,
            'segments_kept' => count($segments),
            'segments_rotated_skipped' => $rotatedSkipped,
            'adaptive_tolerance_pt' => round($adaptiveTolerance, 2),
            'lines_detected' => count($lines),
            'filtered_outlier_lines' => $filteredOutlierLines,
        ];

        return $lines;
    }

    /**
     * @return array{anchors_per_page: array<int, list<array{y: float, x_start: float, x_end: float}>>, page_diagnostics: array<int, array<string, mixed>>, total_lines_detected: int}
     */
    private function extractUsingSmalot(string $inputPath): array
    {
        try {
            $config = new Config();
            $config->setDataTmFontInfoHasToBeIncluded(true);

            $parser = new Parser([], $config);
            $pdf = $parser->parseFile($inputPath);
        } catch (\Throwable $e) {
            Log::warning('[LegalLine] PdfLineExtractor: smalot parse failed, returning no lines', [
                'path' => $inputPath,
                'error' => $e->getMessage(),
            ]);

            return [
                'anchors_per_page' => [],
                'page_diagnostics' => [],
                'total_lines_detected' => 0,
            ];
        }

        $anchorsPerPage = [];
        $pageDiagnostics = [];
        $totalLines = 0;

        foreach ($pdf->getPages() as $index => $page) {
            $pageNo = $index + 1;
            $diagnostic = [];
            try {
                $anchors = $this->getLineAnchorsForPage($page, $pageNo, $diagnostic);
            } catch (\Throwable $e) {
                Log::warning('[LegalLine] PdfLineExtractor: smalot page extraction failed', [
                    'path' => $inputPath,
                    'page' => $pageNo,
                    'error' => $e->getMessage(),
                ]);
                $anchors = [];
                $diagnostic = [
                    'engine' => 'smalot',
                    'page' => $pageNo,
                    'error' => $e->getMessage(),
                    'lines_detected' => 0,
                ];
            }

            if ($anchors !== []) {
                $anchorsPerPage[$pageNo] = $anchors;
                $totalLines += count($anchors);
            }

            $pageDiagnostics[$pageNo] = $diagnostic;
        }

        return [
            'anchors_per_page' => $anchorsPerPage,
            'page_diagnostics' => $pageDiagnostics,
            'total_lines_detected' => $totalLines,
        ];
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

    private function estimateTextWidthPt(string $text, float $fontSize, string $fontId = ''): float
    {
        $cleanText = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($cleanText === '') {
            return 0.0;
        }

        $effectiveFontSize = $fontSize > 0 ? $fontSize : 9.0;
        $fontName = strtolower($fontId);

        if (Str::contains($fontName, ['courier', 'mono'])) {
            return $this->textLength($cleanText) * $effectiveFontSize * 0.60;
        }

        $width = 0.0;
        $chars = preg_split('//u', $cleanText, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $ch) {
            if (preg_match('/\s/u', $ch)) {
                $width += 0.28;
                continue;
            }
            if (preg_match('/[ilIj\|\'`]/u', $ch)) {
                $width += 0.32;
                continue;
            }
            if (preg_match('/[MW@#%&]/u', $ch)) {
                $width += 0.88;
                continue;
            }
            if (preg_match('/[0-9]/u', $ch)) {
                $width += 0.56;
                continue;
            }
            if (preg_match('/[A-Z]/u', $ch)) {
                $width += 0.64;
                continue;
            }
            if (preg_match('/[\.\,\:\;\!\?]/u', $ch)) {
                $width += 0.24;
                continue;
            }

            $width += 0.52;
        }

        return $width * $effectiveFontSize;
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

    /**
     * @param  list<array{x_start: float, x_end: float, y: float}>  $segments
     * @return float
     */
    private function resolveAdaptiveTolerance(array $segments): float
    {
        $base = $this->baseLineTolerancePt();
        if (count($segments) < 6) {
            return $base;
        }

        $ys = array_map(static fn (array $segment): float => $segment['y'], $segments);
        rsort($ys, SORT_NUMERIC);

        $deltas = [];
        for ($i = 0, $max = count($ys) - 1; $i < $max; $i++) {
            $delta = $ys[$i] - $ys[$i + 1];
            if ($delta > 0.2) {
                $deltas[] = $delta;
            }
        }

        if ($deltas === []) {
            return $base;
        }

        $medianSpacing = $this->median($deltas);
        $adaptive = max(
            self::MIN_LINE_Y_TOLERANCE_PT,
            min(self::MAX_LINE_Y_TOLERANCE_PT, $medianSpacing * 0.28)
        );

        return (float) round(($adaptive + $base) / 2, 2);
    }

    /**
     * @param  list<array{x_start: float, x_end: float, y: float}>  $segments
     * @return list<array{y: float, x_start: float, x_end: float}>
     */
    private function groupSegmentsIntoLines(array $segments, float $tolerancePt): array
    {
        usort($segments, function (array $a, array $b) use ($tolerancePt): int {
            $dy = $b['y'] - $a['y'];
            if (abs($dy) > $tolerancePt) {
                return $dy > 0 ? 1 : -1;
            }

            return $a['x_start'] <=> $b['x_start'];
        });

        $lines = [];
        $currentLineY = null;
        $currentLineSegments = [];

        foreach ($segments as $seg) {
            if ($currentLineY === null || abs($seg['y'] - $currentLineY) > $tolerancePt) {
                if ($currentLineSegments !== []) {
                    $lines[] = $this->lineAnchor($currentLineSegments);
                }
                $currentLineY = $seg['y'];
                $currentLineSegments = [$seg];
                continue;
            }

            $currentLineSegments[] = $seg;
        }

        if ($currentLineSegments !== []) {
            $lines[] = $this->lineAnchor($currentLineSegments);
        }

        return $lines;
    }

    /**
     * @param  list<array{y: float, x_start: float, x_end: float}>  $anchors
     * @return list<array{y: float, x_start: float, x_end: float}>
     */
    private function sortAndFilterAnchors(array $anchors, int &$filteredOutlierLines = 0): array
    {
        $filteredOutlierLines = 0;
        if ($anchors === []) {
            return [];
        }

        usort($anchors, function (array $a, array $b): int {
            $dy = $b['y'] <=> $a['y'];
            if ($dy !== 0) {
                return $dy;
            }

            return $a['x_start'] <=> $b['x_start'];
        });

        if (count($anchors) < 5) {
            return $anchors;
        }

        $widths = array_map(
            static fn (array $line): float => max(0.0, $line['x_end'] - $line['x_start']),
            $anchors
        );
        $medianWidth = $this->median($widths);
        $minAllowedWidth = max(10.0, $medianWidth * 0.08);

        $filtered = array_values(array_filter(
            $anchors,
            static fn (array $line): bool => ($line['x_end'] - $line['x_start']) >= $minAllowedWidth
        ));
        $filteredOutlierLines = count($anchors) - count($filtered);

        return $filtered === [] ? $anchors : $filtered;
    }

    private function normalizeAngle(float $angle): float
    {
        $normalized = fmod($angle, 360.0);
        if ($normalized > 180.0) {
            $normalized -= 360.0;
        }
        if ($normalized < -180.0) {
            $normalized += 360.0;
        }

        return $normalized;
    }

    private function isNearHorizontal(float $angleDeg): bool
    {
        $angle = abs($angleDeg);

        return $angle <= self::ROTATION_HORIZONTAL_THRESHOLD_DEG
            || abs(180.0 - $angle) <= self::ROTATION_HORIZONTAL_THRESHOLD_DEG;
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

    private function commandExists(string $command): bool
    {
        $path = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command)));

        return is_string($path) && trim($path) !== '';
    }

    private function baseLineTolerancePt(): float
    {
        $configured = (float) config('line_numbering.y_tolerance_pt', self::DEFAULT_LINE_Y_TOLERANCE_PT);

        return max(self::MIN_LINE_Y_TOLERANCE_PT, min(self::MAX_LINE_Y_TOLERANCE_PT, $configured));
    }

    private function elementFloatAttr(\DOMElement $element, string $name): float
    {
        $raw = $element->getAttribute($name);
        if ($raw === '') {
            $raw = $element->getAttribute(strtolower($name));
        }
        if ($raw === '') {
            $raw = $element->getAttribute(strtoupper($name));
        }

        return is_numeric($raw) ? (float) $raw : 0.0;
    }
}

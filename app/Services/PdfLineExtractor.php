<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

class PdfLineExtractor
{
    private const DEFAULT_LINE_Y_TOLERANCE_PT = 3.0;
    private const MIN_LINE_Y_TOLERANCE_PT = 1.5;
    private const MAX_LINE_Y_TOLERANCE_PT = 8.0;
    private const ROTATION_HORIZONTAL_THRESHOLD_DEG = 8.0;
    private const DEFAULT_POPPLER_BASELINE_RATIO = 0.78;
    private const DEFAULT_OCR_BASELINE_RATIO = 0.82;

    /** @var array<string, mixed> */
    private array $lastDiagnostics = [];

    public function __construct(
        private readonly PdfPageLayoutAnalyzer $pageLayoutAnalyzer,
        private readonly PdfRepeatedArtifactDetector $repeatedArtifactDetector,
        private readonly PdfLineConfidenceScorer $confidenceScorer,
        private readonly PdfOcrLineGridBuilder $ocrLineGridBuilder,
        private readonly PdfScannedPageClassifier $scannedPageClassifier
    ) {}

    /**
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
            'trusted_lines_per_page' => array_map('count', $lineAnchorsPerPage),
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
     * @return array<int, array<int, float>>
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
     * @return array{
     *     success: bool,
     *     reason?: string|null,
     *     anchors_per_page: array<int, list<array{y: float, x_start: float, x_end: float}>>,
     *     page_diagnostics: array<int, array<string, mixed>>,
     *     total_lines_detected: int
     * }
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

        $rawPages = $this->extractRawPagesUsingPoppler($inputPath);
        if (! $rawPages['success']) {
            return [
                'success' => false,
                'reason' => $rawPages['reason'] ?? 'poppler_failed',
                'anchors_per_page' => [],
                'page_diagnostics' => [],
                'total_lines_detected' => 0,
            ];
        }

        return $this->buildTrustedAnchorsFromRawPages($inputPath, $rawPages['pages'], 'poppler');
    }

    /**
     * @return array{
     *     anchors_per_page: array<int, list<array{y: float, x_start: float, x_end: float}>>,
     *     page_diagnostics: array<int, array<string, mixed>>,
     *     total_lines_detected: int
     * }
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

        $pages = [];

        foreach ($pdf->getPages() as $index => $page) {
            $pageNo = $index + 1;

            try {
                $pages[$pageNo] = $this->extractRawPageUsingSmalot($page, $pageNo);
            } catch (\Throwable $e) {
                Log::warning('[LegalLine] PdfLineExtractor: smalot page extraction failed', [
                    'path' => $inputPath,
                    'page' => $pageNo,
                    'error' => $e->getMessage(),
                ]);

                $pages[$pageNo] = [
                    'page_no' => $pageNo,
                    'engine' => 'smalot',
                    'page_width' => 0.0,
                    'page_height' => 0.0,
                    'page_rotation' => 0,
                    'raw_lines' => [],
                    'diagnostic' => [
                        'engine' => 'smalot',
                        'error' => $e->getMessage(),
                        'raw_lines_detected' => 0,
                    ],
                ];
            }
        }

        return $this->buildTrustedAnchorsFromRawPages($inputPath, $pages, 'smalot');
    }

    /**
     * @return array{success: bool, reason?: string|null, pages: array<int, array<string, mixed>>}
     */
    private function extractRawPagesUsingPoppler(string $inputPath): array
    {
        $command = sprintf(
            '%s -bbox-layout -enc UTF-8 %s - 2>/dev/null',
            escapeshellcmd((string) config('line_numbering.poppler_binary', 'pdftotext')),
            escapeshellarg($inputPath)
        );

        $xml = shell_exec($command);
        if (! is_string($xml) || trim($xml) === '') {
            return [
                'success' => false,
                'reason' => 'empty_poppler_output',
                'pages' => [],
            ];
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $loaded = $dom->loadXML($xml);
        if (! $loaded) {
            $loaded = $dom->loadHTML($xml);
        }
        libxml_clear_errors();

        if (! $loaded) {
            return [
                'success' => false,
                'reason' => 'invalid_poppler_html',
                'pages' => [],
            ];
        }

        $xpath = new \DOMXPath($dom);
        $pageNodes = $xpath->query('//*[local-name()="page"]');
        if (! $pageNodes instanceof \DOMNodeList || $pageNodes->length === 0) {
            return [
                'success' => false,
                'reason' => 'no_page_nodes',
                'pages' => [],
            ];
        }

        $pages = [];
        $pageRotations = $this->resolvePageRotations($inputPath);

        foreach ($pageNodes as $index => $pageNode) {
            if (! $pageNode instanceof \DOMElement) {
                continue;
            }

            $pageNo = $index + 1;
            $pageWidth = $this->elementFloatAttr($pageNode, 'width');
            $pageHeight = $this->elementFloatAttr($pageNode, 'height');
            $lineNodes = $xpath->query('.//*[local-name()="line"]', $pageNode);

            $rawLines = [];
            $emptyOrInvalid = 0;
            $lineNodeCount = $lineNodes instanceof \DOMNodeList ? $lineNodes->length : 0;

            if ($lineNodes instanceof \DOMNodeList) {
                $sequence = 0;

                foreach ($lineNodes as $lineNode) {
                    if (! $lineNode instanceof \DOMElement) {
                        continue;
                    }

                    $line = $this->buildRawPopplerLine($xpath, $lineNode, $pageNo, $pageHeight, $sequence);
                    if ($line === null) {
                        $emptyOrInvalid++;
                        continue;
                    }

                    $rawLines[] = $line;
                    $sequence++;
                }
            }

            usort($rawLines, static function (array $a, array $b): int {
                $dy = ((float) $b['y']) <=> ((float) $a['y']);
                if ($dy !== 0) {
                    return $dy;
                }

                return ((float) $a['x_start']) <=> ((float) $b['x_start']);
            });

            $pages[$pageNo] = [
                'page_no' => $pageNo,
                'engine' => 'poppler',
                'page_width' => $pageWidth,
                'page_height' => $pageHeight,
                'page_rotation' => $pageRotations[$pageNo] ?? 0,
                'raw_lines' => $rawLines,
                'diagnostic' => [
                    'engine' => 'poppler',
                    'line_nodes' => $lineNodeCount,
                    'raw_lines_detected' => count($rawLines),
                    'empty_or_invalid_lines_skipped' => $emptyOrInvalid,
                ],
            ];
        }

        return [
            'success' => $pages !== [],
            'reason' => $pages !== [] ? null : 'no_pages_extracted',
            'pages' => $pages,
        ];
    }

    private function extractRawPageUsingSmalot($page, int $pageNo): array
    {
        $data = $page->getDataTm();
        if (! is_array($data) || $data === []) {
            return [
                'page_no' => $pageNo,
                'engine' => 'smalot',
                'page_width' => 0.0,
                'page_height' => 0.0,
                'page_rotation' => 0,
                'raw_lines' => [],
                'diagnostic' => [
                    'engine' => 'smalot',
                    'segments_raw' => 0,
                    'segments_kept' => 0,
                    'segments_rotated_skipped' => 0,
                    'adaptive_tolerance_pt' => $this->baseLineTolerancePt(),
                    'raw_lines_detected' => 0,
                ],
            ];
        }

        $segments = [];
        $segmentsRaw = 0;
        $rotatedSkipped = 0;
        $dominantAngles = [];

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
            $dominantAngles[] = $angleDeg;

            if (! $this->isNearHorizontal($angleDeg)) {
                $rotatedSkipped++;
                continue;
            }

            $text = isset($entry[1]) && is_string($entry[1]) ? $entry[1] : '';
            $normalizedText = trim((string) preg_replace('/\s+/u', ' ', $text));
            if ($normalizedText === '') {
                continue;
            }

            $fontId = isset($entry[2]) ? strtolower((string) $entry[2]) : '';
            $fontSize = isset($entry[3]) && is_numeric($entry[3]) ? (float) $entry[3] : 9.0;
            $estimatedWidth = $this->estimateTextWidthPt($normalizedText, $fontSize, $fontId);
            $height = max(8.0, $fontSize * 1.18);

            $segments[] = [
                'text' => $normalizedText,
                'x_start' => $x,
                'x_end' => $x + $estimatedWidth,
                'y' => $y,
                'top' => $y + ($height * 0.25),
                'bottom' => max(0.0, $y - ($height * 0.75)),
                'height' => $height,
                'font_size' => $fontSize,
            ];
        }

        if ($segments === []) {
            return [
                'page_no' => $pageNo,
                'engine' => 'smalot',
                'page_width' => 0.0,
                'page_height' => 0.0,
                'page_rotation' => $this->dominantPageRotation($dominantAngles),
                'raw_lines' => [],
                'diagnostic' => [
                    'engine' => 'smalot',
                    'segments_raw' => $segmentsRaw,
                    'segments_kept' => 0,
                    'segments_rotated_skipped' => $rotatedSkipped,
                    'adaptive_tolerance_pt' => $this->baseLineTolerancePt(),
                    'raw_lines_detected' => 0,
                ],
            ];
        }

        $adaptiveTolerance = $this->resolveAdaptiveTolerance($segments);
        $rawLines = $this->groupSegmentsIntoRawLines($segments, $adaptiveTolerance, $pageNo, 'smalot');
        $pageWidth = max(72.0, max(array_map(static fn (array $line): float => (float) $line['x_end'], $rawLines)) + 72.0);
        $pageHeight = max(72.0, max(array_map(static fn (array $line): float => (float) $line['top'], $rawLines)) + 72.0);

        return [
            'page_no' => $pageNo,
            'engine' => 'smalot',
            'page_width' => $pageWidth,
            'page_height' => $pageHeight,
            'page_rotation' => $this->dominantPageRotation($dominantAngles),
            'raw_lines' => $rawLines,
            'diagnostic' => [
                'engine' => 'smalot',
                'segments_raw' => $segmentsRaw,
                'segments_kept' => count($segments),
                'segments_rotated_skipped' => $rotatedSkipped,
                'adaptive_tolerance_pt' => round($adaptiveTolerance, 2),
                'raw_lines_detected' => count($rawLines),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array{
     *     success?: bool,
     *     anchors_per_page: array<int, list<array{y: float, x_start: float, x_end: float}>>,
     *     page_diagnostics: array<int, array<string, mixed>>,
     *     total_lines_detected: int
     * }
     */
    private function buildTrustedAnchorsFromRawPages(string $inputPath, array $pages, string $engine): array
    {
        if ($pages === []) {
            return [
                'success' => false,
                'anchors_per_page' => [],
                'page_diagnostics' => [],
                'total_lines_detected' => 0,
            ];
        }

        $pages = $this->maybeApplyOcrFallback($inputPath, $pages, $engine);
        $artifactMarks = $this->repeatedArtifactDetector->detect($pages);

        $anchorsPerPage = [];
        $pageDiagnostics = [];
        $totalLines = 0;

        foreach ($pages as $pageNo => $page) {
            $layout = $this->pageLayoutAnalyzer->analyze($page, $artifactMarks[$pageNo] ?? []);
            $scored = $this->confidenceScorer->scorePage($page, $layout, $artifactMarks[$pageNo] ?? []);
            $trustedAnchors = $scored['trusted_anchors'];
            $ocrGridDiagnostics = null;
            $pageConfidence = (float) ($scored['page_confidence'] ?? 0.0);
            $pageConfidenceLabel = $scored['page_confidence_label'] ?? 'low';
            $lowConfidenceReason = $scored['low_confidence_reason'] ?? null;
            $scannedPageClassification = null;

            if (($page['engine'] ?? null) === 'ocr') {
                $trustedLines = is_array($scored['trusted_lines'] ?? null) ? $scored['trusted_lines'] : [];
                $scannedPageClassification = $this->scannedPageClassifier->classify(
                    $page,
                    $layout,
                    $trustedLines,
                    $trustedAnchors
                );

                if (($scannedPageClassification['should_number'] ?? false) === true) {
                    $ocrGrid = $this->ocrLineGridBuilder->build(
                        $page,
                        $layout,
                        $trustedLines,
                        $trustedAnchors
                    );

                    if ($ocrGrid !== null) {
                        $trustedAnchors = $ocrGrid['anchors'];
                        $ocrGridDiagnostics = $ocrGrid['diagnostics'];
                        $pageConfidence = max(
                            $pageConfidence,
                            max(0.62, (float) config('line_numbering.minimum_page_confidence', 0.58))
                        );
                        $pageConfidenceLabel = $pageConfidence >= max(0.75, (float) config('line_numbering.minimum_page_confidence', 0.58) + 0.12)
                            ? 'high'
                            : 'medium';
                        $lowConfidenceReason = null;
                    }
                } else {
                    $trustedAnchors = [];
                    $pageConfidence = min($pageConfidence, 0.32);
                    $pageConfidenceLabel = 'low';
                    $lowConfidenceReason = 'scanned_page_' . ($scannedPageClassification['reason'] ?? 'not_numbered');
                }
            }

            if ($trustedAnchors !== []) {
                $anchorsPerPage[$pageNo] = $trustedAnchors;
                $totalLines += count($trustedAnchors);
            }

            $baseDiagnostic = is_array($page['diagnostic'] ?? null) ? $page['diagnostic'] : [];
            $pageDiagnostics[$pageNo] = $baseDiagnostic + [
                'engine' => $page['engine'] ?? $engine,
                'page_rotation' => (int) ($page['page_rotation'] ?? 0),
                'raw_lines_detected' => count(is_array($page['raw_lines'] ?? null) ? $page['raw_lines'] : []),
                'trusted_lines_detected' => count($trustedAnchors),
                'suppressed_headers' => (int) (($scored['suppressed_counts']['header'] ?? 0)),
                'suppressed_footers' => (int) (($scored['suppressed_counts']['footer'] ?? 0)),
                'suppressed_short_fragments' => 0,
                'suppressed_structured_lines' => (int) (($scored['suppressed_counts']['structured_content'] ?? 0)),
                'page_confidence' => $pageConfidence,
                'page_confidence_label' => $pageConfidenceLabel,
                'low_confidence_reason' => $lowConfidenceReason,
                'body_region' => $layout['body_region'] ?? null,
                'multi_column_suspected' => (bool) ($layout['multi_column_suspected'] ?? false),
                'table_suspected' => (bool) ($layout['table_suspected'] ?? false),
                'used_ocr_fallback' => (bool) ($page['used_ocr_fallback'] ?? false),
                'used_ocr_grid_reconstruction' => $ocrGridDiagnostics !== null,
                'ocr_grid_diagnostics' => $ocrGridDiagnostics,
                'scanned_page_classification' => $scannedPageClassification,
                'trusted_anchors' => $trustedAnchors,
                'scored_lines' => $scored['scored_lines'] ?? [],
            ];
        }

        return [
            'success' => $anchorsPerPage !== [],
            'anchors_per_page' => $anchorsPerPage,
            'page_diagnostics' => $pageDiagnostics,
            'total_lines_detected' => $totalLines,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function maybeApplyOcrFallback(string $inputPath, array $pages, string $engine): array
    {
        if (! (bool) config('line_numbering.enable_ocr_fallback', false)) {
            return $pages;
        }

        if (! $this->commandExists((string) config('line_numbering.pdftoppm_binary', 'pdftoppm'))
            || ! $this->commandExists((string) config('line_numbering.tesseract_binary', 'tesseract'))) {
            return $pages;
        }

        $artifactMarks = $this->repeatedArtifactDetector->detect($pages);
        $ocrTriggerConfidence = max(0.05, min(0.95, (float) config('line_numbering.ocr_trigger_page_confidence', 0.4)));
        $replacements = [];

        foreach ($pages as $pageNo => $page) {
            $layout = $this->pageLayoutAnalyzer->analyze($page, $artifactMarks[$pageNo] ?? []);
            $scored = $this->confidenceScorer->scorePage($page, $layout, $artifactMarks[$pageNo] ?? []);
            $rawLines = is_array($page['raw_lines'] ?? null) ? $page['raw_lines'] : [];

            $needsOcr = count($rawLines) === 0
                || count($scored['trusted_anchors'] ?? []) < 4
                || ((float) ($scored['page_confidence'] ?? 0.0)) < $ocrTriggerConfidence;

            if (! $needsOcr) {
                continue;
            }

            $ocrPage = $this->extractRawPageUsingOcr($inputPath, $pageNo, (float) ($page['page_width'] ?? 0.0), (float) ($page['page_height'] ?? 0.0));
            if ($ocrPage === null || count($ocrPage['raw_lines']) === 0) {
                continue;
            }

            $ocrPage['used_ocr_fallback'] = true;
            $ocrPage['page_rotation'] = (int) ($page['page_rotation'] ?? 0);
            $replacements[$pageNo] = $ocrPage;
        }

        if ($replacements === []) {
            return $pages;
        }

        foreach ($replacements as $pageNo => $page) {
            $pages[$pageNo] = $page;
        }

        Log::info('[LegalLine] PdfLineExtractor: OCR fallback replaced low-confidence pages', [
            'input_path' => $inputPath,
            'engine' => $engine,
            'pages' => array_keys($replacements),
        ]);

        return $pages;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractRawPageUsingOcr(string $inputPath, int $pageNo, float $pageWidth, float $pageHeight): ?array
    {
        $temporaryBase = tempnam(sys_get_temp_dir(), 'legalline-ocr-');
        if ($temporaryBase === false) {
            return null;
        }

        @unlink($temporaryBase);

        $imageBase = $temporaryBase . '-page';
        $imagePath = $imageBase . '.png';
        $renderDpi = max(180, min(600, (int) config('line_numbering.ocr_render_dpi', 300)));
        $pdftoppmCommand = sprintf(
            '%s -f %d -l %d -singlefile -gray -r %d -png %s %s 2>/dev/null',
            escapeshellcmd((string) config('line_numbering.pdftoppm_binary', 'pdftoppm')),
            $pageNo,
            $pageNo,
            $renderDpi,
            escapeshellarg($inputPath),
            escapeshellarg($imageBase)
        );

        shell_exec($pdftoppmCommand);

        if (! is_file($imagePath)) {
            return null;
        }

        [$imageWidth, $imageHeight] = getimagesize($imagePath) ?: [0, 0];

        if ($pageWidth <= 0 || $pageHeight <= 0) {
            $pageWidth = max(72.0, (float) $imageWidth);
            $pageHeight = max(72.0, (float) $imageHeight);
        }

        $candidateImages = [
            ['variant' => 'original', 'path' => $imagePath],
        ];

        if ((bool) config('line_numbering.ocr_try_enhanced_variant', true)) {
            $enhancedImagePath = $this->createEnhancedOcrImage($imagePath);
            if ($enhancedImagePath !== null) {
                $candidateImages[] = ['variant' => 'enhanced', 'path' => $enhancedImagePath];
            }
        }

        $bestCandidate = null;
        $candidateDiagnostics = [];

        foreach ($candidateImages as $candidateImage) {
            foreach ($this->ocrPsmCandidates() as $psm) {
                $tsv = $this->runTesseractTsv((string) $candidateImage['path'], $psm);
                if ($tsv === null) {
                    continue;
                }

                $rawLines = $this->buildRawOcrLinesFromTsv(
                    $tsv,
                    $pageNo,
                    max(1.0, (float) $imageWidth),
                    max(1.0, (float) $imageHeight),
                    $pageWidth,
                    $pageHeight
                );

                if ($rawLines === []) {
                    continue;
                }

                $candidatePage = [
                    'page_no' => $pageNo,
                    'engine' => 'ocr',
                    'page_width' => $pageWidth,
                    'page_height' => $pageHeight,
                    'page_rotation' => 0,
                    'raw_lines' => $rawLines,
                ];
                $evaluation = $this->evaluateOcrCandidate($candidatePage);
                $classification = $evaluation['classification'];
                $classificationMetrics = is_array($classification['metrics'] ?? null) ? $classification['metrics'] : [];

                $summary = [
                    'variant' => $candidateImage['variant'],
                    'psm' => $psm,
                    'raw_lines_detected' => count($rawLines),
                    'trusted_lines_detected' => count($evaluation['scored']['trusted_anchors'] ?? []),
                    'page_confidence' => round((float) ($evaluation['scored']['page_confidence'] ?? 0.0), 4),
                    'page_confidence_label' => $evaluation['scored']['page_confidence_label'] ?? 'low',
                    'classification_type' => $classification['type'] ?? 'unknown',
                    'classification_should_number' => (bool) ($classification['should_number'] ?? false),
                    'selection_score' => round((float) $evaluation['selection_score'], 4),
                    'body_coverage_ratio' => round((float) ($classificationMetrics['body_coverage_ratio'] ?? 0.0), 4),
                    'bottom_whitespace_ratio' => round((float) ($classificationMetrics['bottom_whitespace_ratio'] ?? 0.0), 4),
                    'dense_body_signal_ratio' => round((float) ($classificationMetrics['dense_body_signal_ratio'] ?? 0.0), 4),
                ];
                $candidateDiagnostics[] = $summary;

                if ($bestCandidate === null
                    || (float) $evaluation['selection_score'] > (float) $bestCandidate['selection_score']) {
                    $bestCandidate = [
                        'page' => $candidatePage,
                        'summary' => $summary,
                        'selection_score' => (float) $evaluation['selection_score'],
                    ];
                }
            }
        }

        foreach ($candidateImages as $candidateImage) {
            @unlink((string) ($candidateImage['path'] ?? ''));
        }

        if ($bestCandidate === null) {
            return null;
        }

        usort($candidateDiagnostics, static fn (array $a, array $b): int => ((float) ($b['selection_score'] ?? 0.0)) <=> ((float) ($a['selection_score'] ?? 0.0)));

        return [
            'page_no' => $pageNo,
            'engine' => 'ocr',
            'page_width' => $pageWidth,
            'page_height' => $pageHeight,
            'page_rotation' => 0,
            'raw_lines' => $bestCandidate['page']['raw_lines'],
            'diagnostic' => [
                'engine' => 'ocr',
                'raw_lines_detected' => count($bestCandidate['page']['raw_lines']),
                'ocr_render_dpi' => $renderDpi,
                'ocr_selected_candidate' => $bestCandidate['summary'],
                'ocr_candidates' => $candidateDiagnostics,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildRawOcrLinesFromTsv(string $tsv, int $pageNo, float $imageWidth, float $imageHeight, float $pageWidth, float $pageHeight): array
    {
        $rows = preg_split("/\r\n|\n|\r/", trim($tsv)) ?: [];
        if ($rows === []) {
            return [];
        }

        array_shift($rows);
        $words = [];
        $groupedWords = [];
        $baselineRatio = max(0.65, min(0.92, (float) config('line_numbering.ocr_baseline_ratio', self::DEFAULT_OCR_BASELINE_RATIO)));
        $minimumWordConfidence = max(0.0, min(100.0, (float) config('line_numbering.ocr_word_min_confidence', 22.0)));

        foreach ($rows as $row) {
            $columns = str_getcsv($row, "\t");
            if (count($columns) < 12 || (int) $columns[0] !== 5) {
                continue;
            }

            $text = trim((string) ($columns[11] ?? ''));
            $confidence = (float) ($columns[10] ?? 0.0);
            if ($text === '' || $confidence < $minimumWordConfidence) {
                continue;
            }

            $left = (float) ($columns[6] ?? 0.0);
            $top = (float) ($columns[7] ?? 0.0);
            $width = (float) ($columns[8] ?? 0.0);
            $height = (float) ($columns[9] ?? 0.0);
            if ($width <= 0 || $height <= 0) {
                continue;
            }

            $xStart = ($left / $imageWidth) * $pageWidth;
            $xEnd = (($left + $width) / $imageWidth) * $pageWidth;
            $wordTop = $pageHeight - (($top / $imageHeight) * $pageHeight);
            $wordBottom = $pageHeight - ((($top + $height) / $imageHeight) * $pageHeight);
            $baseline = $pageHeight - (((($top + ($height * $baselineRatio))) / $imageHeight) * $pageHeight);

            $words[] = [
                'text' => $text,
                'x_start' => $xStart,
                'x_end' => $xEnd,
                'y' => $baseline,
                'top' => $wordTop,
                'bottom' => $wordBottom,
                'height' => max(1.0, $wordTop - $wordBottom),
            ];

            $ocrPage = (int) ($columns[1] ?? 0);
            $ocrBlock = (int) ($columns[2] ?? 0);
            $ocrParagraph = (int) ($columns[3] ?? 0);
            $ocrLine = (int) ($columns[4] ?? 0);
            if ($ocrPage > 0 && $ocrBlock > 0 && $ocrLine > 0) {
                $groupKey = implode(':', [$ocrPage, $ocrBlock, $ocrParagraph, $ocrLine]);
                $groupedWords[$groupKey][] = $words[array_key_last($words)];
            }
        }

        if ($words === []) {
            return [];
        }

        $lineGroups = [];
        foreach ($groupedWords as $groupKey => $groupWords) {
            if ($groupWords === []) {
                continue;
            }

            $lineGroups[] = $this->buildRawLineFromWords($groupWords, $pageNo, 'ocr', count($lineGroups));
        }

        usort($lineGroups, static function (array $a, array $b): int {
            $dy = ((float) ($b['y'] ?? 0.0)) <=> ((float) ($a['y'] ?? 0.0));

            return $dy !== 0 ? $dy : (((float) ($a['x_start'] ?? 0.0)) <=> ((float) ($b['x_start'] ?? 0.0)));
        });

        if (count($lineGroups) >= max(2, (int) floor(count($words) / 10))) {
            return $lineGroups;
        }

        $heights = array_map(static fn (array $word): float => (float) $word['height'], $words);
        $tolerance = max(6.0, $this->median($heights) * 0.55);

        return $this->groupSegmentsIntoRawLines($words, $tolerance, $pageNo, 'ocr');
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{layout: array<string, mixed>, scored: array<string, mixed>, classification: array<string, mixed>, selection_score: float}
     */
    private function evaluateOcrCandidate(array $page): array
    {
        $layout = $this->pageLayoutAnalyzer->analyze($page, []);
        $scored = $this->confidenceScorer->scorePage($page, $layout, []);
        $classification = $this->scannedPageClassifier->classify(
            $page,
            $layout,
            is_array($scored['trusted_lines'] ?? null) ? $scored['trusted_lines'] : [],
            is_array($scored['trusted_anchors'] ?? null) ? $scored['trusted_anchors'] : []
        );
        $metrics = is_array($classification['metrics'] ?? null) ? $classification['metrics'] : [];

        $selectionScore = ((float) ($scored['page_confidence'] ?? 0.0) * 100.0)
            + (count($scored['trusted_anchors'] ?? []) * 2.8)
            + min(18.0, count($page['raw_lines'] ?? []) * 0.55)
            + (((bool) ($classification['should_number'] ?? false)) ? 18.0 : -8.0)
            + (((float) ($metrics['dense_body_signal_ratio'] ?? 0.0)) * 18.0)
            - (((float) ($metrics['short_line_ratio'] ?? 0.0)) * 12.0)
            - (((float) ($metrics['bottom_whitespace_ratio'] ?? 0.0)) * 22.0);

        if ((bool) ($layout['multi_column_suspected'] ?? false)) {
            $selectionScore -= 10.0;
        }

        if ((bool) ($layout['table_suspected'] ?? false)) {
            $selectionScore -= 10.0;
        }

        return [
            'layout' => $layout,
            'scored' => $scored,
            'classification' => $classification,
            'selection_score' => round($selectionScore, 4),
        ];
    }

    /**
     * @return list<int>
     */
    private function ocrPsmCandidates(): array
    {
        $values = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $value): int => (int) $value,
                is_array(config('line_numbering.ocr_psm_candidates', [6])) ? config('line_numbering.ocr_psm_candidates', [6]) : [6]
            ),
            static fn (int $value): bool => $value > 0
        )));

        return $values !== [] ? $values : [6];
    }

    private function runTesseractTsv(string $imagePath, int $psm): ?string
    {
        $tsv = shell_exec(sprintf(
            '%s %s stdout tsv --psm %d 2>/dev/null',
            escapeshellcmd((string) config('line_numbering.tesseract_binary', 'tesseract')),
            escapeshellarg($imagePath),
            $psm
        ));

        return is_string($tsv) && trim($tsv) !== '' ? $tsv : null;
    }

    private function createEnhancedOcrImage(string $imagePath): ?string
    {
        if (! function_exists('imagecreatefrompng')
            || ! function_exists('imagefilter')
            || ! function_exists('imagepng')) {
            return null;
        }

        $image = @imagecreatefrompng($imagePath);
        if ($image === false) {
            return null;
        }

        imagefilter($image, IMG_FILTER_GRAYSCALE);
        imagefilter($image, IMG_FILTER_CONTRAST, -35);
        @imagefilter($image, IMG_FILTER_BRIGHTNESS, 8);
        if (defined('IMG_FILTER_MEAN_REMOVAL')) {
            @imagefilter($image, IMG_FILTER_MEAN_REMOVAL);
        }

        $enhancedPath = preg_replace('/\.png$/i', '-enhanced.png', $imagePath) ?: ($imagePath . '-enhanced.png');
        $written = @imagepng($image, $enhancedPath);
        imagedestroy($image);

        return $written && is_file($enhancedPath) ? $enhancedPath : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRawPopplerLine(\DOMXPath $xpath, \DOMElement $lineNode, int $pageNo, float $pageHeight, int $sequence): ?array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $lineNode->textContent));
        if ($text === '') {
            return null;
        }

        $xMin = $this->elementFloatAttr($lineNode, 'xMin');
        $xMax = $this->elementFloatAttr($lineNode, 'xMax');
        $yMin = $this->elementFloatAttr($lineNode, 'yMin');
        $yMax = $this->elementFloatAttr($lineNode, 'yMax');

        if ($xMax <= $xMin || $yMax <= $yMin || $pageHeight <= 0) {
            return null;
        }

        $wordNodes = $xpath->query('.//*[local-name()="word"]', $lineNode);
        $words = [];

        if ($wordNodes instanceof \DOMNodeList) {
            foreach ($wordNodes as $wordNode) {
                if (! $wordNode instanceof \DOMElement) {
                    continue;
                }

                $word = $this->buildRawPopplerWord($wordNode, $pageHeight);
                if ($word !== null) {
                    $words[] = $word;
                }
            }
        }

        $lineHeight = max(0.1, $yMax - $yMin);
        $baselineY = $this->resolvePopplerBaselineYFromWords($words, $pageHeight, $yMin, $lineHeight);
        $top = $pageHeight - $yMin;
        $bottom = $pageHeight - $yMax;

        return [
            'id' => sprintf('p%d-poppler-%d', $pageNo, $sequence),
            'text' => $text,
            'x_start' => $xMin,
            'x_end' => $xMax,
            'y' => $baselineY,
            'top' => $top,
            'bottom' => $bottom,
            'height' => max(0.1, $top - $bottom),
            'char_count' => $this->textLength($text),
            'words' => $words,
            'source' => 'poppler',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRawPopplerWord(\DOMElement $wordNode, float $pageHeight): ?array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $wordNode->textContent));
        if ($text === '') {
            return null;
        }

        $xMin = $this->elementFloatAttr($wordNode, 'xMin');
        $xMax = $this->elementFloatAttr($wordNode, 'xMax');
        $yMin = $this->elementFloatAttr($wordNode, 'yMin');
        $yMax = $this->elementFloatAttr($wordNode, 'yMax');
        if ($xMax <= $xMin || $yMax <= $yMin) {
            return null;
        }

        $height = $yMax - $yMin;
        $baselineFromTop = $yMin + ($height * max(0.60, min(0.90, (float) config('line_numbering.poppler_baseline_ratio', self::DEFAULT_POPPLER_BASELINE_RATIO))));

        return [
            'text' => $text,
            'x_start' => $xMin,
            'x_end' => $xMax,
            'y' => max(0.0, $pageHeight - $baselineFromTop),
            'top' => $pageHeight - $yMin,
            'bottom' => $pageHeight - $yMax,
            'height' => $height,
        ];
    }

    private function resolvePopplerBaselineYFromWords(array $words, float $pageHeight, float $lineYMin, float $lineHeight): float
    {
        if ($words === []) {
            $baselineFromTop = $lineYMin + ($lineHeight * max(0.60, min(0.90, (float) config('line_numbering.poppler_baseline_ratio', self::DEFAULT_POPPLER_BASELINE_RATIO))));

            return max(0.0, $pageHeight - $baselineFromTop);
        }

        return $this->median(array_map(
            static fn (array $word): float => (float) ($word['y'] ?? 0.0),
            $words
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     * @return list<array<string, mixed>>
     */
    private function groupSegmentsIntoRawLines(array $segments, float $tolerancePt, int $pageNo, string $source): array
    {
        usort($segments, function (array $a, array $b) use ($tolerancePt): int {
            $dy = ((float) $b['y']) - ((float) $a['y']);
            if (abs($dy) > $tolerancePt) {
                return $dy > 0 ? 1 : -1;
            }

            return ((float) $a['x_start']) <=> ((float) $b['x_start']);
        });

        $lines = [];
        $currentLineY = null;
        $currentLineSegments = [];

        foreach ($segments as $segment) {
            if ($currentLineY === null || abs(((float) $segment['y']) - $currentLineY) > $tolerancePt) {
                if ($currentLineSegments !== []) {
                    $lines[] = $this->buildRawLineFromWords($currentLineSegments, $pageNo, $source, count($lines));
                }

                $currentLineSegments = [$segment];
                $currentLineY = (float) $segment['y'];
                continue;
            }

            $currentLineSegments[] = $segment;
        }

        if ($currentLineSegments !== []) {
            $lines[] = $this->buildRawLineFromWords($currentLineSegments, $pageNo, $source, count($lines));
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $words
     * @return array<string, mixed>
     */
    private function buildRawLineFromWords(array $words, int $pageNo, string $source, int $sequence): array
    {
        usort($words, static fn (array $a, array $b): int => ((float) $a['x_start']) <=> ((float) $b['x_start']));

        $text = trim(implode(' ', array_map(
            static fn (array $word): string => trim((string) ($word['text'] ?? '')),
            $words
        )));
        $xStart = min(array_map(static fn (array $word): float => (float) ($word['x_start'] ?? 0.0), $words));
        $xEnd = max(array_map(static fn (array $word): float => (float) ($word['x_end'] ?? 0.0), $words));
        $y = $this->median(array_map(static fn (array $word): float => (float) ($word['y'] ?? 0.0), $words));
        $top = max(array_map(static fn (array $word): float => (float) ($word['top'] ?? 0.0), $words));
        $bottom = min(array_map(static fn (array $word): float => (float) ($word['bottom'] ?? 0.0), $words));

        return [
            'id' => sprintf('p%d-%s-%d', $pageNo, $source, $sequence),
            'text' => $text,
            'x_start' => $xStart,
            'x_end' => $xEnd,
            'y' => $y,
            'top' => $top,
            'bottom' => $bottom,
            'height' => max(0.1, $top - $bottom),
            'char_count' => $this->textLength($text),
            'words' => array_values($words),
            'source' => $source,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function resolvePageRotations(string $inputPath): array
    {
        $binary = trim((string) shell_exec('command -v pdfinfo 2>/dev/null'));
        if ($binary === '') {
            return [];
        }

        $output = shell_exec(sprintf('%s %s 2>/dev/null', escapeshellcmd($binary), escapeshellarg($inputPath)));
        if (! is_string($output) || trim($output) === '') {
            return [];
        }

        if (preg_match('/Page rot:\s+(-?\d+)/i', $output, $match) !== 1) {
            return [];
        }

        return [1 => (int) $match[1]];
    }

    /**
     * @param  list<float>  $angles
     */
    private function dominantPageRotation(array $angles): int
    {
        if ($angles === []) {
            return 0;
        }

        $normalized = array_map(function (float $angle): int {
            $angle = (int) round($angle / 90) * 90;
            $angle = $angle % 360;

            if ($angle < 0) {
                $angle += 360;
            }

            return $angle;
        }, $angles);

        $counts = array_count_values($normalized);
        arsort($counts);

        return (int) array_key_first($counts);
    }

    private function estimateTextWidthPt(string $text, float $fontSize, string $fontId = ''): float
    {
        $cleanText = trim((string) preg_replace('/\s+/u', ' ', $text));
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
        foreach ($chars as $character) {
            if (preg_match('/\s/u', $character)) {
                $width += 0.28;
                continue;
            }
            if (preg_match('/[ilIj\|\'`]/u', $character)) {
                $width += 0.32;
                continue;
            }
            if (preg_match('/[MW@#%&]/u', $character)) {
                $width += 0.88;
                continue;
            }
            if (preg_match('/[0-9]/u', $character)) {
                $width += 0.56;
                continue;
            }
            if (preg_match('/[A-Z]/u', $character)) {
                $width += 0.64;
                continue;
            }
            if (preg_match('/[\.\,\:\;\!\?]/u', $character)) {
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
     * @param  list<array<string, mixed>>  $segments
     */
    private function resolveAdaptiveTolerance(array $segments): float
    {
        $base = $this->baseLineTolerancePt();
        if (count($segments) < 6) {
            return $base;
        }

        $ys = array_map(static fn (array $segment): float => (float) ($segment['y'] ?? 0.0), $segments);
        rsort($ys, SORT_NUMERIC);

        $deltas = [];
        for ($index = 0, $max = count($ys) - 1; $index < $max; $index++) {
            $delta = $ys[$index] - $ys[$index + 1];
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

<?php

namespace App\Services;

use App\Services\Scanned\TextractJobCoordinator;
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

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $pageImageMetricsCache = [];

    public function __construct(
        private readonly PdfPageLayoutAnalyzer $pageLayoutAnalyzer,
        private readonly PdfRepeatedArtifactDetector $repeatedArtifactDetector,
        private readonly PdfLineConfidenceScorer $confidenceScorer,
        private readonly PdfOcrLineGridBuilder $ocrLineGridBuilder,
        private readonly PdfScannedPageClassifier $scannedPageClassifier,
        private readonly TextractJobCoordinator $textractJobCoordinator
    ) {}

    /**
     * @return array<int, array<int, array{y: float, x_start: float, x_end: float}>>
     */
    public function getLineAnchorsPerPage(string $inputPath, array $context = []): array
    {
        $enginePreference = strtolower((string) config('line_numbering.extractor_engine', 'auto'));
        $this->lastDiagnostics = [
            'engine_preference' => $enginePreference,
            'engine_used' => null,
            'fallback_to_smalot' => false,
            'pages' => [],
            'total_lines_detected' => 0,
            'input_path' => $inputPath,
            'ocr' => [
                'enabled' => $this->ocrFallbackEnabled(),
                'candidate_pages' => [],
                'pages_replaced' => [],
                'providers_attempted' => [],
                'providers_used' => [],
                'textract' => null,
                'local' => null,
            ],
        ];

        $lineAnchorsPerPage = [];

        if (in_array($enginePreference, ['auto', 'poppler'], true)) {
            $poppler = $this->extractUsingPoppler($inputPath, $context);
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
            $smalot = $this->extractUsingSmalot($inputPath, $context);
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
            'ocr_summary' => $this->lastDiagnostics['ocr'],
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
    private function extractUsingPoppler(string $inputPath, array $context = []): array
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

        return $this->buildTrustedAnchorsFromRawPages($inputPath, $rawPages['pages'], 'poppler', $context);
    }

    /**
     * @return array{
     *     anchors_per_page: array<int, list<array{y: float, x_start: float, x_end: float}>>,
     *     page_diagnostics: array<int, array<string, mixed>>,
     *     total_lines_detected: int
     * }
     */
    private function extractUsingSmalot(string $inputPath, array $context = []): array
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

        return $this->buildTrustedAnchorsFromRawPages($inputPath, $pages, 'smalot', $context);
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
    private function buildTrustedAnchorsFromRawPages(string $inputPath, array $pages, string $engine, array $context = []): array
    {
        if ($pages === []) {
            return [
                'success' => false,
                'anchors_per_page' => [],
                'page_diagnostics' => [],
                'total_lines_detected' => 0,
            ];
        }

        $pages = $this->maybeApplyOcrFallback($inputPath, $pages, $engine, $context);
        $artifactMarks = $this->repeatedArtifactDetector->detect($pages);
        $imageBasedPageMetrics = $this->detectImageBasedPages($inputPath, $pages);

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
                'ocr_provider' => $page['ocr_provider'] ?? ($baseDiagnostic['ocr_provider'] ?? null),
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
                'table_row_count' => (int) ($layout['table_row_count'] ?? 0),
                'used_ocr_fallback' => (bool) ($page['used_ocr_fallback'] ?? false),
                'used_ocr_grid_reconstruction' => $ocrGridDiagnostics !== null,
                'ocr_grid_diagnostics' => $ocrGridDiagnostics,
                'scanned_page_classification' => $scannedPageClassification,
                'image_based_page_metrics' => $imageBasedPageMetrics[$pageNo] ?? null,
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
    private function maybeApplyOcrFallback(string $inputPath, array $pages, string $engine, array $context = []): array
    {
        if (! $this->ocrFallbackEnabled()) {
            return $pages;
        }

        $artifactMarks = $this->repeatedArtifactDetector->detect($pages);
        $ocrTriggerConfidence = max(0.05, min(0.95, (float) config('line_numbering.ocr_trigger_page_confidence', 0.4)));
        $imageBasedPageMetrics = $this->detectImageBasedPages($inputPath, $pages);
        $candidatePages = [];

        foreach ($pages as $pageNo => $page) {
            $layout = $this->pageLayoutAnalyzer->analyze($page, $artifactMarks[$pageNo] ?? []);
            $scored = $this->confidenceScorer->scorePage($page, $layout, $artifactMarks[$pageNo] ?? []);
            $rawLines = is_array($page['raw_lines'] ?? null) ? $page['raw_lines'] : [];
            $trustedAnchors = is_array($scored['trusted_anchors'] ?? null) ? $scored['trusted_anchors'] : [];
            $pageConfidence = (float) ($scored['page_confidence'] ?? 0.0);
            $imageMetrics = is_array($imageBasedPageMetrics[$pageNo] ?? null) ? $imageBasedPageMetrics[$pageNo] : [];
            $isMostlyImageBased = (bool) ($imageMetrics['is_mostly_image_based'] ?? false);

            $reason = null;
            if (count($rawLines) === 0) {
                $reason = 'no_extractable_lines';
            } elseif ($isMostlyImageBased) {
                $reason = 'page_is_mostly_image_based';
            } elseif (count($trustedAnchors) < 4) {
                $reason = 'too_few_trusted_anchors';
            } elseif ($pageConfidence < $ocrTriggerConfidence) {
                $reason = 'low_page_confidence';
            }

            if ($reason === null) {
                continue;
            }

            $candidatePages[$pageNo] = [
                'reason' => $reason,
                'page_confidence' => round($pageConfidence, 4),
                'trusted_anchor_count' => count($trustedAnchors),
                'raw_line_count' => count($rawLines),
                'image_based_page' => $isMostlyImageBased,
                'image_based_page_metrics' => $imageMetrics !== [] ? $imageMetrics : null,
            ];
        }

        $this->lastDiagnostics['ocr']['candidate_pages'] = array_keys($candidatePages);
        $this->lastDiagnostics['ocr']['candidate_details'] = $candidatePages;

        if ($candidatePages === []) {
            return $pages;
        }

        $replacements = [];
        $remainingPages = $candidatePages;

        Log::info('[LegalLine] PdfLineExtractor: OCR fallback candidates identified', [
            'input_path' => $inputPath,
            'engine' => $engine,
            'candidate_pages' => array_keys($candidatePages),
            'candidate_details' => $candidatePages,
            'textract_enabled' => $this->textractJobCoordinator->enabled(),
            'local_ocr_enabled' => (bool) config('line_numbering.enable_ocr_fallback', false),
        ]);

        $textractDecision = $this->textractEligibilityDecision($pages, $candidatePages);
        $this->lastDiagnostics['ocr']['textract_decision'] = $textractDecision;

        if ($this->textractJobCoordinator->enabled() && ($textractDecision['should_use'] ?? false)) {
            $this->lastDiagnostics['ocr']['providers_attempted'][] = 'textract';

            try {
                $textractRun = $this->extractPagesUsingTextract($inputPath, $pages, $candidatePages, $context);
                $this->lastDiagnostics['ocr']['textract'] = $textractRun['summary'];

                foreach ($textractRun['pages'] as $pageNo => $ocrPage) {
                    $ocrPage['used_ocr_fallback'] = true;
                    $ocrPage['page_rotation'] = (int) ($pages[$pageNo]['page_rotation'] ?? 0);
                    $replacements[$pageNo] = $ocrPage;
                    unset($remainingPages[$pageNo]);
                }

                if ($textractRun['pages'] !== []) {
                    $this->lastDiagnostics['ocr']['providers_used'][] = 'textract';
                }
            } catch (\Throwable $e) {
                $this->lastDiagnostics['ocr']['textract'] = [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'pages_replaced' => [],
                ];

                Log::warning('[LegalLine] PdfLineExtractor: Textract fallback failed, will try local OCR if available', [
                    'input_path' => $inputPath,
                    'engine' => $engine,
                    'candidate_pages' => array_keys($candidatePages),
                    'message' => $e->getMessage(),
                ]);
            }
        } elseif ($this->textractJobCoordinator->enabled()) {
            $this->lastDiagnostics['ocr']['textract'] = [
                'status' => 'skipped',
                'reason' => $textractDecision['reason'] ?? 'not_eligible',
                'pages_replaced' => [],
            ];

            Log::info('[LegalLine] PdfLineExtractor: skipping Textract fallback for this document', [
                'input_path' => $inputPath,
                'engine' => $engine,
                'candidate_pages' => array_keys($candidatePages),
                'reason' => $textractDecision['reason'] ?? 'not_eligible',
                'summary' => $textractDecision,
            ]);
        }

        $localOcrEnabled = (bool) config('line_numbering.enable_ocr_fallback', false);
        $localOcrAvailable = $this->localOcrAvailable();
        $localSummary = [
            'available' => $localOcrAvailable,
            'pages_attempted' => array_keys($remainingPages),
            'pages_replaced' => [],
        ];

        if ($remainingPages !== [] && $localOcrEnabled && $localOcrAvailable) {
            $this->lastDiagnostics['ocr']['providers_attempted'][] = 'tesseract';

            foreach (array_keys($remainingPages) as $pageNo) {
                $page = $pages[$pageNo];
                $ocrPage = $this->extractRawPageUsingOcr($inputPath, $pageNo, (float) ($page['page_width'] ?? 0.0), (float) ($page['page_height'] ?? 0.0));
                if ($ocrPage === null || count($ocrPage['raw_lines']) === 0) {
                    continue;
                }

                $ocrPage['used_ocr_fallback'] = true;
                $ocrPage['page_rotation'] = (int) ($page['page_rotation'] ?? 0);
                $replacements[$pageNo] = $ocrPage;
                $localSummary['pages_replaced'][] = $pageNo;
            }

            if ($localSummary['pages_replaced'] !== []) {
                $this->lastDiagnostics['ocr']['providers_used'][] = 'tesseract';
            }
        } elseif ($remainingPages !== []) {
            Log::info('[LegalLine] PdfLineExtractor: local OCR fallback unavailable for remaining pages', [
                'input_path' => $inputPath,
                'engine' => $engine,
                'remaining_pages' => array_keys($remainingPages),
                'local_ocr_enabled' => $localOcrEnabled,
                'local_ocr_available' => $localOcrAvailable,
            ]);
        }

        $this->lastDiagnostics['ocr']['local'] = $localSummary;

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
            'providers_used' => $this->lastDiagnostics['ocr']['providers_used'],
        ]);

        $this->lastDiagnostics['ocr']['pages_replaced'] = array_keys($replacements);

        return $pages;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, array<string, mixed>>  $candidatePages
     * @return array<string, mixed>
     */
    private function textractEligibilityDecision(array $pages, array $candidatePages): array
    {
        $totalPages = count($pages);
        $candidateCount = count($candidatePages);
        $candidateRatio = $totalPages > 0 ? round($candidateCount / $totalPages, 4) : 0.0;
        $noExtractableLinePages = [];
        $lowConfidencePages = [];
        $imageBasedPages = [];

        foreach ($candidatePages as $pageNo => $details) {
            $reason = (string) ($details['reason'] ?? '');
            if ($reason === 'no_extractable_lines') {
                $noExtractableLinePages[] = $pageNo;
            }

            if ($reason === 'page_is_mostly_image_based') {
                $imageBasedPages[] = $pageNo;
            }

            if ($reason === 'low_page_confidence') {
                $lowConfidencePages[] = $pageNo;
            }
        }

        $allPagesAreCandidates = $candidateCount > 0 && $candidateCount === $totalPages;
        $likelyScannedDocument = $allPagesAreCandidates
            || count($imageBasedPages) >= max(1, min(3, (int) ceil($totalPages * 0.1)))
            || ($candidateRatio >= 0.1 && $imageBasedPages !== [])
            || $candidateRatio >= 0.2
            || count($noExtractableLinePages) >= max(1, min(3, (int) ceil($totalPages * 0.1)))
            || ($totalPages <= 12 && (count($noExtractableLinePages) >= 1 || count($imageBasedPages) >= 1));

        if ($likelyScannedDocument) {
            return [
                'should_use' => true,
                'reason' => 'document_looks_scanned_or_ocr_dependent',
                'total_pages' => $totalPages,
                'candidate_count' => $candidateCount,
                'candidate_ratio' => $candidateRatio,
                'no_extractable_line_pages' => $noExtractableLinePages,
                'image_based_pages' => $imageBasedPages,
                'low_confidence_pages' => $lowConfidencePages,
            ];
        }

        return [
            'should_use' => false,
            'reason' => 'candidate_pages_do_not_justify_whole_document_textract',
            'total_pages' => $totalPages,
            'candidate_count' => $candidateCount,
            'candidate_ratio' => $candidateRatio,
            'no_extractable_line_pages' => $noExtractableLinePages,
            'image_based_pages' => $imageBasedPages,
            'low_confidence_pages' => $lowConfidencePages,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function detectImageBasedPages(string $inputPath, array $pages): array
    {
        $cacheKey = sha1($inputPath . '|' . (is_file($inputPath) ? ((string) filemtime($inputPath) . '|' . (string) filesize($inputPath)) : 'missing'));
        if (isset($this->pageImageMetricsCache[$cacheKey])) {
            return $this->pageImageMetricsCache[$cacheKey];
        }

        $binary = (string) config('line_numbering.pdfimages_binary', 'pdfimages');
        if (! $this->commandExists($binary) || ! is_file($inputPath)) {
            return $this->pageImageMetricsCache[$cacheKey] = [];
        }

        $output = shell_exec(sprintf('%s -list %s 2>/dev/null', escapeshellcmd($binary), escapeshellarg($inputPath)));
        if (! is_string($output) || trim($output) === '') {
            return $this->pageImageMetricsCache[$cacheKey] = [];
        }

        $coverageThreshold = max(0.15, min(0.98, (float) config('line_numbering.image_page_coverage_threshold', 0.62)));
        $axisThreshold = max(0.15, min(0.98, (float) config('line_numbering.image_page_axis_coverage_threshold', 0.78)));
        $metrics = [];
        $lines = preg_split("/\r\n|\n|\r/", trim($output)) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, 'page') || str_starts_with($trimmed, '-')) {
                continue;
            }

            $parts = preg_split('/\s+/', $trimmed) ?: [];
            if (count($parts) < 12 || ! ctype_digit((string) ($parts[0] ?? ''))) {
                continue;
            }

            $pageNo = (int) $parts[0];
            $type = strtolower((string) ($parts[2] ?? ''));
            if ($type !== 'image') {
                continue;
            }

            $pageWidth = max(1.0, (float) ($pages[$pageNo]['page_width'] ?? 0.0));
            $pageHeight = max(1.0, (float) ($pages[$pageNo]['page_height'] ?? 0.0));
            if ($pageWidth <= 1.0 || $pageHeight <= 1.0) {
                continue;
            }

            $widthPixels = max(0.0, (float) ($parts[3] ?? 0.0));
            $heightPixels = max(0.0, (float) ($parts[4] ?? 0.0));
            $xPpi = max(0.0, (float) ($parts[count($parts) - 4] ?? 0.0));
            $yPpi = max(0.0, (float) ($parts[count($parts) - 3] ?? 0.0));
            if ($widthPixels <= 0.0 || $heightPixels <= 0.0 || $xPpi <= 0.0 || $yPpi <= 0.0) {
                continue;
            }

            $renderedWidthPt = ($widthPixels / $xPpi) * 72.0;
            $renderedHeightPt = ($heightPixels / $yPpi) * 72.0;
            $widthRatio = min(1.5, $renderedWidthPt / $pageWidth);
            $heightRatio = min(1.5, $renderedHeightPt / $pageHeight);
            $coverageRatio = min(1.5, ($renderedWidthPt * $renderedHeightPt) / ($pageWidth * $pageHeight));

            $pageMetric = $metrics[$pageNo] ?? [
                'image_count' => 0,
                'dominant_image_coverage_ratio' => 0.0,
                'dominant_image_width_ratio' => 0.0,
                'dominant_image_height_ratio' => 0.0,
                'is_mostly_image_based' => false,
            ];

            $pageMetric['image_count']++;
            if ($coverageRatio > (float) $pageMetric['dominant_image_coverage_ratio']) {
                $pageMetric['dominant_image_coverage_ratio'] = round($coverageRatio, 4);
                $pageMetric['dominant_image_width_ratio'] = round($widthRatio, 4);
                $pageMetric['dominant_image_height_ratio'] = round($heightRatio, 4);
            }

            $pageMetric['is_mostly_image_based'] = $pageMetric['is_mostly_image_based']
                || $coverageRatio >= $coverageThreshold
                || ($widthRatio >= $axisThreshold && $heightRatio >= $axisThreshold);

            $metrics[$pageNo] = $pageMetric;
        }

        return $this->pageImageMetricsCache[$cacheKey] = $metrics;
    }

    private function ocrFallbackEnabled(): bool
    {
        return (bool) config('line_numbering.enable_ocr_fallback', false)
            || $this->textractJobCoordinator->enabled();
    }

    private function localOcrAvailable(): bool
    {
        return $this->commandExists((string) config('line_numbering.pdftoppm_binary', 'pdftoppm'))
            && $this->commandExists((string) config('line_numbering.tesseract_binary', 'tesseract'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, array<string, mixed>>  $candidatePages
     * @return array{pages: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    private function extractPagesUsingTextract(string $inputPath, array $pages, array $candidatePages, array $context = []): array
    {
        $pdfJobId = $this->resolveProcessingJobId($inputPath, $context);
        $pageDimensions = $this->textractJobCoordinator->resolvePageDimensions($inputPath);

        Log::info('[LegalLine] PdfLineExtractor: starting Textract OCR fallback', [
            'input_path' => $inputPath,
            'pdf_job_id' => $pdfJobId,
            'candidate_pages' => array_keys($candidatePages),
            'page_dimensions_resolved' => count($pageDimensions),
        ]);

        $textractOptions = [];
        $processingStateCallback = $context['processing_state_callback'] ?? null;
        if (is_callable($processingStateCallback)) {
            $textractOptions['progress_callback'] = $processingStateCallback;
        }

        $run = $this->textractJobCoordinator->runSynchronous($pdfJobId, $inputPath, $pageDimensions, $textractOptions);
        $normalizedPages = is_array($run['pages'] ?? null) ? $run['pages'] : [];
        $replacements = [];

        foreach (array_keys($candidatePages) as $pageNo) {
            $ocrPage = is_array($normalizedPages[$pageNo] ?? null) ? $normalizedPages[$pageNo] : null;
            if ($ocrPage === null || count($ocrPage['raw_lines'] ?? []) === 0) {
                continue;
            }

            $ocrPage['ocr_provider'] = 'textract';
            $ocrPage['used_ocr_fallback'] = true;
            $ocrPage['page_rotation'] = (int) ($pages[$pageNo]['page_rotation'] ?? 0);
            $replacements[$pageNo] = $ocrPage;
        }

        $summary = [
            'status' => $run['status'] ?? 'UNKNOWN',
            'job_id' => $run['job_id'] ?? null,
            'bucket' => $run['bucket'] ?? null,
            'source_key' => $run['source_key'] ?? null,
            'result_path' => $run['result_path'] ?? null,
            'started_at' => $run['started_at'] ?? null,
            'completed_at' => $run['completed_at'] ?? null,
            'poll_attempts' => $run['poll_attempts'] ?? null,
            'status_message' => $run['status_message'] ?? null,
            'pages_available' => array_keys($normalizedPages),
            'pages_replaced' => array_keys($replacements),
            'warnings' => $run['warnings'] ?? [],
            'source_deleted' => $run['source_deleted'] ?? false,
        ];

        Log::info('[LegalLine] PdfLineExtractor: Textract OCR fallback completed', [
            'input_path' => $inputPath,
            'pdf_job_id' => $pdfJobId,
            'job_id' => $summary['job_id'],
            'status' => $summary['status'],
            'pages_replaced' => $summary['pages_replaced'],
            'pages_available' => $summary['pages_available'],
            'result_path' => $summary['result_path'],
        ]);

        return [
            'pages' => $replacements,
            'summary' => $summary,
        ];
    }

    private function resolveProcessingJobId(string $inputPath, array $context = []): string
    {
        $jobId = trim((string) ($context['pdf_job_id'] ?? ''));
        if ($jobId !== '') {
            return $jobId;
        }

        $normalizedPath = str_replace('\\', '/', $inputPath);
        if (preg_match('#/pdf-jobs/([^/]+)/input\.pdf$#', $normalizedPath, $matches) === 1) {
            return (string) $matches[1];
        }

        return 'adhoc-' . substr(sha1($normalizedPath), 0, 16);
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
                    'ocr_provider' => 'tesseract',
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
            'ocr_provider' => 'tesseract',
            'page_width' => $pageWidth,
            'page_height' => $pageHeight,
            'page_rotation' => 0,
            'raw_lines' => $bestCandidate['page']['raw_lines'],
            'diagnostic' => [
                'engine' => 'ocr',
                'ocr_provider' => 'tesseract',
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

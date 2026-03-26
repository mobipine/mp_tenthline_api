<?php

namespace App\Services\Scanned;

class TextractLineNormalizer
{
    public function __construct(
        private readonly TextractGeometryMapper $geometryMapper
    ) {}

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<int, array<string, mixed>>  $pageDimensions
     * @return array<int, array<string, mixed>>
     */
    public function normalize(array $blocks, array $pageDimensions = [], array $options = []): array
    {
        $groupedByPage = [];
        $blocksById = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $id = trim((string) ($block['Id'] ?? ''));
            if ($id !== '') {
                $blocksById[$id] = $block;
            }

            $pageNo = $this->pageNo($block);
            if ($pageNo === null) {
                continue;
            }

            $groupedByPage[$pageNo] ??= [];
            $groupedByPage[$pageNo][] = $block;
        }

        if ($groupedByPage === []) {
            return [];
        }

        ksort($groupedByPage);

        $minimumLineConfidence = (float) ($options['minimum_line_confidence'] ?? config('textract.minimum_line_confidence', 0.0));
        $baselineRatio = (float) ($options['baseline_ratio'] ?? config('textract.baseline_ratio', 0.82));
        $pages = [];

        foreach ($groupedByPage as $pageNo => $pageBlocks) {
            $dimensions = $this->resolvePageDimensions($pageDimensions, $pageNo);
            $lineBlocksSeen = 0;
            $wordBlocksSeen = 0;
            $filteredLowConfidence = 0;
            $rawLines = [];
            $lineConfidenceTotal = 0.0;

            foreach ($pageBlocks as $block) {
                if (($block['BlockType'] ?? null) === 'WORD') {
                    $wordBlocksSeen++;
                    continue;
                }

                if (($block['BlockType'] ?? null) !== 'LINE') {
                    continue;
                }

                $lineBlocksSeen++;
                $text = $this->normalizeText((string) ($block['Text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $confidence = is_numeric($block['Confidence'] ?? null) ? (float) $block['Confidence'] : 0.0;
                if ($confidence < $minimumLineConfidence) {
                    $filteredLowConfidence++;
                    continue;
                }

                $boundingBox = is_array($block['Geometry']['BoundingBox'] ?? null)
                    ? $block['Geometry']['BoundingBox']
                    : null;

                if ($boundingBox === null) {
                    continue;
                }

                $coordinates = $this->geometryMapper->mapBoundingBox(
                    $boundingBox,
                    $dimensions['width'],
                    $dimensions['height'],
                    $baselineRatio
                );
                $words = $this->resolveLineWords($block, $blocksById, $pageNo, $dimensions, $baselineRatio);
                $lineConfidenceTotal += $confidence;

                $rawLines[] = [
                    'id' => (string) ($block['Id'] ?? ('p' . $pageNo . '-textract-' . count($rawLines))),
                    'text' => $text,
                    'x_start' => $coordinates['x_start'],
                    'x_end' => $coordinates['x_end'],
                    'y' => $coordinates['y'],
                    'top' => $coordinates['top'],
                    'bottom' => $coordinates['bottom'],
                    'height' => $coordinates['height'],
                    'char_count' => $this->stringLength($text),
                    'words' => $words,
                    'source' => 'textract',
                    'confidence' => round($confidence, 3),
                ];
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
                'engine' => 'ocr',
                'ocr_provider' => 'textract',
                'page_width' => $dimensions['width'],
                'page_height' => $dimensions['height'],
                'page_rotation' => 0,
                'raw_lines' => $rawLines,
                'diagnostic' => [
                    'engine' => 'ocr',
                    'ocr_provider' => 'textract',
                    'textract_blocks_seen' => count($pageBlocks),
                    'textract_line_blocks_seen' => $lineBlocksSeen,
                    'textract_word_blocks_seen' => $wordBlocksSeen,
                    'textract_lines_retained' => count($rawLines),
                    'textract_lines_filtered_low_confidence' => $filteredLowConfidence,
                    'textract_average_line_confidence' => count($rawLines) > 0
                        ? round($lineConfidenceTotal / count($rawLines), 3)
                        : 0.0,
                ],
            ];
        }

        return $pages;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private function pageNo(array $block): ?int
    {
        if (! isset($block['Page']) || ! is_numeric($block['Page'])) {
            return null;
        }

        $pageNo = (int) $block['Page'];

        return $pageNo > 0 ? $pageNo : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $pageDimensions
     * @return array{width: float, height: float}
     */
    private function resolvePageDimensions(array $pageDimensions, int $pageNo): array
    {
        $page = is_array($pageDimensions[$pageNo] ?? null) ? $pageDimensions[$pageNo] : [];
        $width = $page['width'] ?? $page['page_width'] ?? config('textract.default_page_width_pt', 612.0);
        $height = $page['height'] ?? $page['page_height'] ?? config('textract.default_page_height_pt', 792.0);

        return [
            'width' => max(1.0, (float) $width),
            'height' => max(1.0, (float) $height),
        ];
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

    /**
     * @param  array<string, mixed>  $lineBlock
     * @param  array<string, array<string, mixed>>  $blocksById
     * @param  array{width: float, height: float}  $dimensions
     * @return list<array<string, mixed>>
     */
    private function resolveLineWords(array $lineBlock, array $blocksById, int $pageNo, array $dimensions, float $baselineRatio): array
    {
        $words = [];
        $relationships = is_array($lineBlock['Relationships'] ?? null) ? $lineBlock['Relationships'] : [];

        foreach ($relationships as $relationship) {
            if (! is_array($relationship) || ($relationship['Type'] ?? null) !== 'CHILD') {
                continue;
            }

            $ids = is_array($relationship['Ids'] ?? null) ? $relationship['Ids'] : [];
            foreach ($ids as $id) {
                $child = is_array($blocksById[(string) $id] ?? null) ? $blocksById[(string) $id] : null;
                if ($child === null || ($child['BlockType'] ?? null) !== 'WORD') {
                    continue;
                }

                $text = $this->normalizeText((string) ($child['Text'] ?? ''));
                $boundingBox = is_array($child['Geometry']['BoundingBox'] ?? null)
                    ? $child['Geometry']['BoundingBox']
                    : null;

                if ($text === '' || $boundingBox === null) {
                    continue;
                }

                $coordinates = $this->geometryMapper->mapBoundingBox(
                    $boundingBox,
                    $dimensions['width'],
                    $dimensions['height'],
                    $baselineRatio
                );

                $words[] = [
                    'id' => (string) ($child['Id'] ?? ('p' . $pageNo . '-word-' . count($words))),
                    'text' => $text,
                    'x_start' => $coordinates['x_start'],
                    'x_end' => $coordinates['x_end'],
                    'y' => $coordinates['y'],
                    'top' => $coordinates['top'],
                    'bottom' => $coordinates['bottom'],
                    'height' => $coordinates['height'],
                    'confidence' => round(is_numeric($child['Confidence'] ?? null) ? (float) $child['Confidence'] : 0.0, 3),
                    'source' => 'textract',
                ];
            }
        }

        usort($words, static fn (array $a, array $b): int => ((float) $a['x_start']) <=> ((float) $b['x_start']));

        return $words;
    }
}

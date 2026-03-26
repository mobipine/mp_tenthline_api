<?php

namespace Tests\Unit\Scanned;

use App\Services\Scanned\TextractGeometryMapper;
use App\Services\Scanned\TextractLineNormalizer;
use Tests\TestCase;

class TextractLineNormalizerTest extends TestCase
{
    public function test_it_normalizes_textract_blocks_into_internal_pages_and_lines(): void
    {
        config([
            'textract.minimum_line_confidence' => 85.0,
            'textract.baseline_ratio' => 0.82,
        ]);

        $normalizer = new TextractLineNormalizer(new TextractGeometryMapper());
        $pages = $normalizer->normalize($this->sampleBlocks(), [
            1 => ['width' => 612.0, 'height' => 792.0],
        ]);

        $this->assertCount(1, $pages);
        $this->assertSame('ocr', $pages[1]['engine']);
        $this->assertSame('textract', $pages[1]['ocr_provider']);
        $this->assertCount(1, $pages[1]['raw_lines']);

        $line = $pages[1]['raw_lines'][0];
        $this->assertSame('Hello world', $line['text']);
        $this->assertSame(11, $line['char_count']);
        $this->assertSame('textract', $line['source']);
        $this->assertSame(99.0, $line['confidence']);
        $this->assertCount(2, $line['words']);
        $this->assertSame('Hello', $line['words'][0]['text']);
        $this->assertSame('world', $line['words'][1]['text']);

        $diagnostic = $pages[1]['diagnostic'];
        $this->assertSame(2, $diagnostic['textract_line_blocks_seen']);
        $this->assertSame(1, $diagnostic['textract_lines_retained']);
        $this->assertSame(1, $diagnostic['textract_lines_filtered_low_confidence']);
    }

    public function test_it_preserves_page_shells_when_textract_returns_empty_pages(): void
    {
        $normalizer = new TextractLineNormalizer(new TextractGeometryMapper());
        $pages = $normalizer->normalize([
            ['Id' => 'page-1', 'BlockType' => 'PAGE', 'Page' => 1],
        ], [
            1 => ['width' => 612.0, 'height' => 792.0],
        ]);

        $this->assertCount(1, $pages);
        $this->assertSame([], $pages[1]['raw_lines']);
        $this->assertSame(0, $pages[1]['diagnostic']['textract_line_blocks_seen']);
        $this->assertSame(0, $pages[1]['diagnostic']['textract_lines_retained']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleBlocks(): array
    {
        return [
            [
                'Id' => 'page-1',
                'BlockType' => 'PAGE',
                'Page' => 1,
            ],
            [
                'Id' => 'line-1',
                'BlockType' => 'LINE',
                'Page' => 1,
                'Text' => 'Hello   world',
                'Confidence' => 99.0,
                'Geometry' => [
                    'BoundingBox' => [
                        'Left' => 0.1,
                        'Top' => 0.2,
                        'Width' => 0.4,
                        'Height' => 0.03,
                    ],
                ],
                'Relationships' => [
                    [
                        'Type' => 'CHILD',
                        'Ids' => ['word-1', 'word-2'],
                    ],
                ],
            ],
            [
                'Id' => 'word-1',
                'BlockType' => 'WORD',
                'Page' => 1,
                'Text' => 'Hello',
                'Confidence' => 98.0,
                'Geometry' => [
                    'BoundingBox' => [
                        'Left' => 0.1,
                        'Top' => 0.2,
                        'Width' => 0.12,
                        'Height' => 0.03,
                    ],
                ],
            ],
            [
                'Id' => 'word-2',
                'BlockType' => 'WORD',
                'Page' => 1,
                'Text' => 'world',
                'Confidence' => 97.0,
                'Geometry' => [
                    'BoundingBox' => [
                        'Left' => 0.24,
                        'Top' => 0.2,
                        'Width' => 0.14,
                        'Height' => 0.03,
                    ],
                ],
            ],
            [
                'Id' => 'line-2',
                'BlockType' => 'LINE',
                'Page' => 1,
                'Text' => 'filtered out',
                'Confidence' => 12.0,
                'Geometry' => [
                    'BoundingBox' => [
                        'Left' => 0.1,
                        'Top' => 0.5,
                        'Width' => 0.3,
                        'Height' => 0.03,
                    ],
                ],
            ],
        ];
    }
}

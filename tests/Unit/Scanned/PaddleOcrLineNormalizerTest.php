<?php

namespace Tests\Unit\Scanned;

use App\Services\Scanned\PaddleOcrLineNormalizer;
use Tests\TestCase;

class PaddleOcrLineNormalizerTest extends TestCase
{
    private PaddleOcrLineNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new PaddleOcrLineNormalizer();
    }

    /**
     * Page 612x792pt rendered at 1224x1584px => 0.5 scale on both axes.
     */
    private function box(float $x1, float $x2, float $yTop, float $yBottom, string $text, float $conf = 0.9): array
    {
        return [
            'bbox' => [[$x1, $yTop], [$x2, $yTop], [$x2, $yBottom], [$x1, $yBottom]],
            'text' => $text,
            'confidence' => $conf,
        ];
    }

    private function normalize(array $lines, array $options = []): array
    {
        return $this->normalizer->normalizePage(
            ['lines' => $lines],
            ['width' => 612.0, 'height' => 792.0],
            1224,
            1584,
            1,
            $options
        );
    }

    public function test_it_merges_fragments_that_share_a_visual_row_into_one_line(): void
    {
        $result = $this->normalize([
            // Row 1: an indented clause marker plus its body text.
            $this->box(100, 140, 100, 130, '1.'),
            $this->box(200, 500, 100, 130, 'Plaintiff alleges the following.'),
            // Row 2: a single fragment on its own line.
            $this->box(120, 480, 200, 230, 'Second line of the paragraph.'),
        ]);

        $lines = $result['raw_lines'];

        $this->assertCount(2, $lines, 'Two visual rows should yield two numbered lines.');
        $this->assertSame('1. Plaintiff alleges the following.', $lines[0]['text']);
        $this->assertSame(2, $lines[0]['fragment_count']);
        $this->assertSame('Second line of the paragraph.', $lines[1]['text']);
        $this->assertSame(1, $lines[1]['fragment_count']);
    }

    public function test_disabling_clustering_keeps_every_fragment_as_its_own_line(): void
    {
        $result = $this->normalize([
            $this->box(100, 140, 100, 130, '1.'),
            $this->box(200, 500, 100, 130, 'Plaintiff alleges the following.'),
            $this->box(120, 480, 200, 230, 'Second line of the paragraph.'),
        ], ['row_cluster_enabled' => false]);

        $this->assertCount(3, $result['raw_lines']);
    }

    public function test_it_splits_a_box_that_spans_multiple_rows(): void
    {
        $result = $this->normalize([
            $this->box(100, 480, 100, 130, 'First normal line here.'),
            $this->box(100, 480, 200, 230, 'Second normal line here.'),
            $this->box(100, 480, 300, 330, 'Third normal line here.'),
            $this->box(100, 480, 400, 430, 'Fourth normal line here.'),
            // Double-height fragment (60px => 30pt vs 15pt median) => two rows.
            $this->box(100, 480, 600, 660, 'merged tall'),
        ]);

        $lines = $result['raw_lines'];

        $this->assertCount(6, $lines, 'The tall fragment should split into two lines.');
        foreach ($lines as $line) {
            $this->assertNotSame('', $line['text']);
        }
    }

    public function test_each_table_row_clusters_into_exactly_one_line(): void
    {
        // A three-column table: every cell is its own OCR fragment, but each
        // visual row must count as exactly one text line.
        $result = $this->normalize([
            // Row 1 (header)
            $this->box(100, 220, 100, 130, 'Piece'),
            $this->box(300, 420, 100, 130, 'Symbol'),
            $this->box(500, 620, 100, 130, 'Value'),
            // Row 2
            $this->box(100, 220, 200, 230, 'Queen'),
            $this->box(300, 420, 200, 230, 'Q'),
            $this->box(500, 620, 200, 230, '9'),
            // Row 3
            $this->box(100, 220, 300, 330, 'Rook'),
            $this->box(300, 420, 300, 330, 'R'),
            $this->box(500, 620, 300, 330, '5'),
        ]);

        $lines = $result['raw_lines'];

        $this->assertCount(3, $lines, 'Three table rows should yield three numbered lines.');
        $this->assertSame('Piece Symbol Value', $lines[0]['text']);
        $this->assertSame('Queen Q 9', $lines[1]['text']);
        $this->assertSame('Rook R 5', $lines[2]['text']);
        foreach ($lines as $line) {
            $this->assertSame(3, $line['fragment_count']);
        }
    }

    public function test_baseline_sits_near_the_bottom_of_the_ocr_box(): void
    {
        // Box top 100px..bottom 130px at 0.5 scale => top 742pt, bottom 727pt.
        $result = $this->normalize([
            $this->box(100, 480, 100, 130, 'Baseline check line.'),
        ]);

        $line = $result['raw_lines'][0];

        // With the default baseline ratio (0.20) the label baseline must sit in
        // the lower third of the box, ON the line, not floating near its top.
        $this->assertEqualsWithDelta(727.0 + (15.0 * 0.20), (float) $line['y'], 0.01);
    }

    public function test_low_confidence_fragments_are_filtered_before_clustering(): void
    {
        $result = $this->normalize([
            $this->box(100, 480, 100, 130, 'Kept line.', 0.9),
            $this->box(100, 480, 200, 230, 'Dropped line.', 0.1),
        ], ['minimum_line_confidence' => 0.5]);

        $this->assertCount(1, $result['raw_lines']);
        $this->assertSame('Kept line.', $result['raw_lines'][0]['text']);
        $this->assertSame(1, $result['diagnostic']['paddleocr_lines_filtered_low_confidence']);
    }
}

<?php

namespace Tests\Unit;

use App\Services\PdfLineConfidenceScorer;
use Tests\TestCase;

class PdfLineConfidenceScorerTest extends TestCase
{
    public function test_it_preserves_short_body_lines_while_suppressing_repeated_artifacts(): void
    {
        $scorer = app(PdfLineConfidenceScorer::class);

        $page = [
            'page_width' => 612.0,
            'page_height' => 792.0,
            'page_rotation' => 0,
            'raw_lines' => [
                ['id' => 'header', 'text' => 'Case No. 100 of 2026', 'x_start' => 72.0, 'x_end' => 260.0, 'y' => 760.0, 'top' => 768.0, 'bottom' => 752.0, 'char_count' => 20, 'words' => []],
                ['id' => 'body-9', 'text' => 'P1L09 regular body text', 'x_start' => 72.0, 'x_end' => 320.0, 'y' => 620.0, 'top' => 628.0, 'bottom' => 612.0, 'char_count' => 22, 'words' => []],
                ['id' => 'body-10', 'text' => 'SHORT10', 'x_start' => 72.0, 'x_end' => 132.0, 'y' => 600.0, 'top' => 608.0, 'bottom' => 592.0, 'char_count' => 7, 'words' => []],
                ['id' => 'body-11', 'text' => 'P1L11 regular body text', 'x_start' => 72.0, 'x_end' => 320.0, 'y' => 580.0, 'top' => 588.0, 'bottom' => 572.0, 'char_count' => 22, 'words' => []],
                ['id' => 'footer', 'text' => 'Page 1', 'x_start' => 280.0, 'x_end' => 340.0, 'y' => 40.0, 'top' => 48.0, 'bottom' => 32.0, 'char_count' => 6, 'words' => []],
            ],
        ];

        $layout = [
            'page_width' => 612.0,
            'page_height' => 792.0,
            'body_region' => ['left' => 60.0, 'right' => 340.0, 'top' => 640.0, 'bottom' => 560.0],
            'median_line_spacing' => 20.0,
            'multi_column_suspected' => false,
            'table_suspected' => false,
            'dominant_column' => null,
        ];

        $result = $scorer->scorePage($page, $layout, [
            'header' => 'header',
            'footer' => 'footer',
        ]);

        $trustedTexts = array_map(static fn (array $line): string => (string) ($line['text'] ?? ''), $result['trusted_lines']);

        $this->assertContains('SHORT10', $trustedTexts);
        $this->assertGreaterThanOrEqual(3, count($result['trusted_lines']));
        $this->assertGreaterThan(0.58, $result['page_confidence']);
        $this->assertSame(1, $result['suppressed_counts']['header']);
        $this->assertSame(1, $result['suppressed_counts']['footer']);
    }

    public function test_it_marks_rotated_sparse_pages_as_low_confidence(): void
    {
        $scorer = app(PdfLineConfidenceScorer::class);

        $page = [
            'page_width' => 612.0,
            'page_height' => 792.0,
            'page_rotation' => 270,
            'raw_lines' => [
                ['id' => 'line-1', 'text' => 'ROTATED LINE 1', 'x_start' => 90.0, 'x_end' => 220.0, 'y' => 660.0, 'top' => 668.0, 'bottom' => 652.0, 'char_count' => 14, 'words' => []],
                ['id' => 'line-2', 'text' => 'ROTATED LINE 2', 'x_start' => 300.0, 'x_end' => 430.0, 'y' => 500.0, 'top' => 508.0, 'bottom' => 492.0, 'char_count' => 14, 'words' => []],
            ],
        ];

        $layout = [
            'page_width' => 612.0,
            'page_height' => 792.0,
            'body_region' => ['left' => 60.0, 'right' => 250.0, 'top' => 680.0, 'bottom' => 620.0],
            'median_line_spacing' => 0.0,
            'multi_column_suspected' => true,
            'table_suspected' => false,
            'dominant_column' => ['left' => 60.0, 'right' => 250.0],
        ];

        $result = $scorer->scorePage($page, $layout, []);

        $this->assertSame('low', $result['page_confidence_label']);
        $this->assertLessThan(0.58, $result['page_confidence']);
        $this->assertNotNull($result['low_confidence_reason']);
    }

    public function test_it_counts_each_detected_table_row_once_even_when_cells_are_split(): void
    {
        $scorer = app(PdfLineConfidenceScorer::class);

        $page = [
            'page_width' => 612.0,
            'page_height' => 792.0,
            'page_rotation' => 0,
            'raw_lines' => [
                ['id' => 'body-1', 'text' => 'The respondent relied on the schedule below.', 'x_start' => 72.0, 'x_end' => 360.0, 'y' => 700.0, 'top' => 708.0, 'bottom' => 692.0, 'char_count' => 42, 'words' => []],
                ['id' => 'table-r1c1', 'text' => '31-Jan-2016', 'x_start' => 72.0, 'x_end' => 146.0, 'y' => 620.0, 'top' => 628.0, 'bottom' => 612.0, 'char_count' => 11, 'words' => []],
                ['id' => 'table-r1c2', 'text' => '190,667.72', 'x_start' => 236.0, 'x_end' => 304.0, 'y' => 620.0, 'top' => 628.0, 'bottom' => 612.0, 'char_count' => 10, 'words' => []],
                ['id' => 'table-r1c3', 'text' => '11,751.83', 'x_start' => 394.0, 'x_end' => 452.0, 'y' => 620.0, 'top' => 628.0, 'bottom' => 612.0, 'char_count' => 9, 'words' => []],
                ['id' => 'table-r2c1', 'text' => '30-Apr-2016', 'x_start' => 72.0, 'x_end' => 146.0, 'y' => 588.0, 'top' => 596.0, 'bottom' => 580.0, 'char_count' => 11, 'words' => []],
                ['id' => 'table-r2c2', 'text' => '189,804.82', 'x_start' => 236.0, 'x_end' => 304.0, 'y' => 588.0, 'top' => 596.0, 'bottom' => 580.0, 'char_count' => 10, 'words' => []],
                ['id' => 'table-r2c3', 'text' => '11,496.36', 'x_start' => 394.0, 'x_end' => 452.0, 'y' => 588.0, 'top' => 596.0, 'bottom' => 580.0, 'char_count' => 9, 'words' => []],
                ['id' => 'body-2', 'text' => 'The parties disputed whether the schedule was complete.', 'x_start' => 72.0, 'x_end' => 430.0, 'y' => 540.0, 'top' => 548.0, 'bottom' => 532.0, 'char_count' => 55, 'words' => []],
            ],
        ];

        $layout = [
            'page_width' => 612.0,
            'page_height' => 792.0,
            'body_region' => ['left' => 60.0, 'right' => 470.0, 'top' => 716.0, 'bottom' => 520.0],
            'median_line_spacing' => 28.0,
            'multi_column_suspected' => false,
            'table_suspected' => true,
            'dominant_column' => null,
            'table_row_count' => 2,
            'table_row_line_ids' => ['table-r1c1', 'table-r1c2', 'table-r1c3', 'table-r2c1', 'table-r2c2', 'table-r2c3'],
            'table_row_anchors' => [
                ['id' => 'table-row-1', 'text' => '31-Jan-2016 190,667.72 11,751.83', 'x_start' => 72.0, 'x_end' => 452.0, 'y' => 620.0],
                ['id' => 'table-row-2', 'text' => '30-Apr-2016 189,804.82 11,496.36', 'x_start' => 72.0, 'x_end' => 452.0, 'y' => 588.0],
            ],
        ];

        $result = $scorer->scorePage($page, $layout, []);
        $trustedYs = array_map(static fn (array $anchor): float => (float) ($anchor['y'] ?? 0.0), $result['trusted_anchors']);

        $this->assertContains(620.0, $trustedYs);
        $this->assertContains(588.0, $trustedYs);
        $this->assertCount(4, $result['trusted_anchors']);
        $this->assertSame(6, $result['suppressed_counts']['structured_content']);
        $this->assertGreaterThan(0.58, $result['page_confidence']);
    }

    public function test_it_does_not_double_count_left_margin_clause_markers_on_the_same_baseline(): void
    {
        $scorer = app(PdfLineConfidenceScorer::class);

        $page = [
            'page_width' => 595.2,
            'page_height' => 841.44,
            'page_rotation' => 0,
            'raw_lines' => [
                ['id' => 'line-1', 'text' => '13.5 Clause 32.1 provides that the Guarantee and Indemnity is governed', 'x_start' => 82.0, 'x_end' => 488.0, 'y' => 349.5, 'top' => 351.7, 'bottom' => 339.4, 'char_count' => 66, 'words' => []],
                ['id' => 'line-2', 'text' => 'by and shall be construed in accordance with the laws of Kenya;', 'x_start' => 120.2, 'x_end' => 467.5, 'y' => 332.1, 'top' => 334.2, 'bottom' => 322.0, 'char_count' => 62, 'words' => []],
                ['id' => 'line-3', 'text' => 'Advancement of monies pursuant to the Facility Agreement', 'x_start' => 75.7, 'x_end' => 435.6, 'y' => 169.5, 'top' => 171.5, 'bottom' => 160.2, 'char_count' => 56, 'words' => []],
                ['id' => 'line-4', 'text' => 'Pursuant to the terms of the Facility Agreement and the waiver letter', 'x_start' => 113.8, 'x_end' => 481.9, 'y' => 140.753, 'top' => 142.9, 'bottom' => 130.9, 'char_count' => 69, 'words' => []],
                ['id' => 'line-5', 'text' => '14,', 'x_start' => 76.2, 'x_end' => 92.18, 'y' => 139.766, 'top' => 141.41, 'bottom' => 132.275, 'char_count' => 3, 'words' => []],
                ['id' => 'line-6', 'text' => 'dated 29 July 2015 entered into between the First Defendant and', 'x_start' => 112.8, 'x_end' => 480.7, 'y' => 122.821, 'top' => 124.9, 'bottom' => 113.3, 'char_count' => 63, 'words' => []],
            ],
        ];

        $layout = [
            'page_width' => 595.2,
            'page_height' => 841.44,
            'body_region' => ['left' => 77.707, 'right' => 507.281, 'top' => 752.83, 'bottom' => 101.317],
            'median_line_spacing' => 17.4,
            'multi_column_suspected' => false,
            'table_suspected' => false,
            'dominant_column' => null,
        ];

        $result = $scorer->scorePage($page, $layout, []);
        $trustedTexts = array_map(static fn (array $line): string => (string) ($line['text'] ?? ''), $result['trusted_lines']);

        $this->assertContains('Pursuant to the terms of the Facility Agreement and the waiver letter', $trustedTexts);
        $this->assertNotContains('14,', $trustedTexts);
        $this->assertSame(1, $result['suppressed_counts']['structured_content']);
        $this->assertCount(5, $result['trusted_anchors']);
    }
}

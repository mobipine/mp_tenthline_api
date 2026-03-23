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
}

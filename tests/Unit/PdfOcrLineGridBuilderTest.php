<?php

namespace Tests\Unit;

use App\Services\PdfOcrLineGridBuilder;
use Tests\TestCase;

class PdfOcrLineGridBuilderTest extends TestCase
{
    public function test_it_reconstructs_missing_scanned_lines_into_a_regular_grid(): void
    {
        $builder = app(PdfOcrLineGridBuilder::class);

        $page = [
            'engine' => 'ocr',
        ];

        $layout = [
            'page_width' => 612.0,
            'page_height' => 792.0,
            'body_region' => [
                'left' => 72.0,
                'right' => 300.0,
                'top' => 714.0,
                'bottom' => 114.0,
            ],
            'median_line_spacing' => 24.0,
            'multi_column_suspected' => false,
            'table_suspected' => false,
        ];

        $trustedLines = [
            ['y' => 690.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 666.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 642.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 618.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 594.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 546.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 522.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 498.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 474.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 450.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 426.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 402.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 378.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 354.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 330.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 282.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 258.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 234.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 210.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 186.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 162.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 138.0, 'x_start' => 72.0, 'x_end' => 300.0],
        ];

        $trustedAnchors = array_map(static fn (array $line): array => [
            'y' => $line['y'],
            'x_start' => $line['x_start'],
            'x_end' => $line['x_end'],
        ], $trustedLines);

        $grid = $builder->build($page, $layout, $trustedLines, $trustedAnchors);

        $this->assertNotNull($grid);
        $this->assertTrue(($grid['diagnostics']['mode'] ?? null) === 'ocr_grid_reconstruction');
        $this->assertCount(26, $grid['anchors']);
        $this->assertSame(690.0, $grid['anchors'][1]['y']);
        $this->assertSame(570.0, $grid['anchors'][6]['y']);
        $this->assertSame(306.0, $grid['anchors'][17]['y']);
        $this->assertGreaterThan($trustedAnchors[0]['y'], $grid['anchors'][0]['y']);
    }

    public function test_it_refuses_to_project_a_grid_into_sparse_top_heavy_scan_layouts(): void
    {
        $builder = app(PdfOcrLineGridBuilder::class);

        $page = [
            'engine' => 'ocr',
        ];

        $layout = [
            'page_width' => 612.0,
            'page_height' => 792.0,
            'body_region' => [
                'left' => 72.0,
                'right' => 248.0,
                'top' => 716.0,
                'bottom' => 382.0,
            ],
            'median_line_spacing' => 24.0,
            'multi_column_suspected' => false,
            'table_suspected' => false,
        ];

        $trustedLines = [
            ['y' => 690.0, 'x_start' => 72.0, 'x_end' => 210.0],
            ['y' => 666.0, 'x_start' => 72.0, 'x_end' => 225.0],
            ['y' => 642.0, 'x_start' => 72.0, 'x_end' => 188.0],
            ['y' => 618.0, 'x_start' => 72.0, 'x_end' => 205.0],
            ['y' => 594.0, 'x_start' => 72.0, 'x_end' => 196.0],
            ['y' => 500.0, 'x_start' => 72.0, 'x_end' => 240.0],
            ['y' => 476.0, 'x_start' => 72.0, 'x_end' => 232.0],
            ['y' => 452.0, 'x_start' => 72.0, 'x_end' => 214.0],
        ];

        $trustedAnchors = array_map(static fn (array $line): array => [
            'y' => $line['y'],
            'x_start' => $line['x_start'],
            'x_end' => $line['x_end'],
        ], $trustedLines);

        $grid = $builder->build($page, $layout, $trustedLines, $trustedAnchors);

        $this->assertNull($grid);
    }
}

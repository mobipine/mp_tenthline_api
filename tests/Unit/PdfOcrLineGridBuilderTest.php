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
                'top' => 702.0,
                'bottom' => 578.0,
            ],
            'median_line_spacing' => 40.0,
            'multi_column_suspected' => false,
            'table_suspected' => false,
        ];

        $trustedLines = [
            ['y' => 680.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 660.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 620.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 600.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 580.0, 'x_start' => 72.0, 'x_end' => 300.0],
            ['y' => 560.0, 'x_start' => 72.0, 'x_end' => 300.0],
        ];

        $trustedAnchors = array_map(static fn (array $line): array => [
            'y' => $line['y'],
            'x_start' => $line['x_start'],
            'x_end' => $line['x_end'],
        ], $trustedLines);

        $grid = $builder->build($page, $layout, $trustedLines, $trustedAnchors);

        $this->assertNotNull($grid);
        $this->assertTrue(($grid['diagnostics']['mode'] ?? null) === 'ocr_grid_reconstruction');
        $this->assertCount(7, $grid['anchors']);
        $this->assertSame(680.0, $grid['anchors'][1]['y']);
        $this->assertSame(660.0, $grid['anchors'][2]['y']);
        $this->assertSame(620.0, $grid['anchors'][4]['y']);
        $this->assertSame(600.0, $grid['anchors'][5]['y']);
        $this->assertGreaterThan($trustedAnchors[0]['y'], $grid['anchors'][0]['y']);
    }
}

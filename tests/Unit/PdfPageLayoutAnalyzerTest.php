<?php

namespace Tests\Unit;

use App\Services\PdfPageLayoutAnalyzer;
use Tests\TestCase;

class PdfPageLayoutAnalyzerTest extends TestCase
{
    public function test_it_identifies_a_dominant_column_and_body_region(): void
    {
        $analyzer = app(PdfPageLayoutAnalyzer::class);

        $page = [
            'page_width' => 612.0,
            'page_height' => 792.0,
            'raw_lines' => [
                ['id' => 'l1', 'text' => 'Left column line 1', 'x_start' => 72.0, 'x_end' => 288.0, 'y' => 700.0, 'top' => 708.0, 'bottom' => 692.0, 'char_count' => 18, 'words' => []],
                ['id' => 'l2', 'text' => 'Left column line 2', 'x_start' => 72.0, 'x_end' => 284.0, 'y' => 680.0, 'top' => 688.0, 'bottom' => 672.0, 'char_count' => 18, 'words' => []],
                ['id' => 'l3', 'text' => 'Left column line 3', 'x_start' => 72.0, 'x_end' => 290.0, 'y' => 660.0, 'top' => 668.0, 'bottom' => 652.0, 'char_count' => 18, 'words' => []],
                ['id' => 'l4', 'text' => 'Left column line 4', 'x_start' => 72.0, 'x_end' => 286.0, 'y' => 640.0, 'top' => 648.0, 'bottom' => 632.0, 'char_count' => 18, 'words' => []],
                ['id' => 'r1', 'text' => 'Side note 1', 'x_start' => 380.0, 'x_end' => 520.0, 'y' => 698.0, 'top' => 706.0, 'bottom' => 690.0, 'char_count' => 10, 'words' => []],
                ['id' => 'r2', 'text' => 'Side note 2', 'x_start' => 380.0, 'x_end' => 520.0, 'y' => 678.0, 'top' => 686.0, 'bottom' => 670.0, 'char_count' => 10, 'words' => []],
                ['id' => 'r3', 'text' => 'Side note 3', 'x_start' => 380.0, 'x_end' => 520.0, 'y' => 658.0, 'top' => 666.0, 'bottom' => 650.0, 'char_count' => 10, 'words' => []],
                ['id' => 'r4', 'text' => 'Side note 4', 'x_start' => 380.0, 'x_end' => 520.0, 'y' => 638.0, 'top' => 646.0, 'bottom' => 630.0, 'char_count' => 10, 'words' => []],
            ],
        ];

        $layout = $analyzer->analyze($page);

        $this->assertTrue($layout['multi_column_suspected']);
        $this->assertLessThan(330.0, $layout['body_region']['right']);
        $this->assertGreaterThan(60.0, $layout['body_region']['left']);
        $this->assertGreaterThan(0.0, $layout['median_line_spacing']);
    }
}

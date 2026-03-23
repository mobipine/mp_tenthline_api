<?php

namespace Tests\Unit;

use App\Services\PdfRepeatedArtifactDetector;
use Tests\TestCase;

class PdfRepeatedArtifactDetectorTest extends TestCase
{
    public function test_it_marks_repeated_header_and_footer_lines_without_suppressing_body_lines(): void
    {
        $detector = app(PdfRepeatedArtifactDetector::class);

        $pages = [
            1 => [
                'page_height' => 792.0,
                'raw_lines' => [
                    ['id' => 'p1-h', 'text' => 'Case No. 100 of 2026', 'y' => 760.0, 'top' => 770.0, 'bottom' => 750.0],
                    ['id' => 'p1-body', 'text' => 'P1L10 body text', 'y' => 620.0, 'top' => 628.0, 'bottom' => 612.0],
                    ['id' => 'p1-f', 'text' => 'Page 1', 'y' => 38.0, 'top' => 44.0, 'bottom' => 28.0],
                ],
            ],
            2 => [
                'page_height' => 792.0,
                'raw_lines' => [
                    ['id' => 'p2-h', 'text' => 'Case No. 101 of 2026', 'y' => 759.0, 'top' => 769.0, 'bottom' => 749.0],
                    ['id' => 'p2-body', 'text' => 'P2L10 body text', 'y' => 618.0, 'top' => 626.0, 'bottom' => 610.0],
                    ['id' => 'p2-f', 'text' => 'Page 2', 'y' => 39.0, 'top' => 45.0, 'bottom' => 29.0],
                ],
            ],
        ];

        $marks = $detector->detect($pages);

        $this->assertSame('header', $marks[1]['p1-h'] ?? null);
        $this->assertSame('header', $marks[2]['p2-h'] ?? null);
        $this->assertSame('footer', $marks[1]['p1-f'] ?? null);
        $this->assertSame('footer', $marks[2]['p2-f'] ?? null);
        $this->assertArrayNotHasKey('p1-body', $marks[1] ?? []);
        $this->assertArrayNotHasKey('p2-body', $marks[2] ?? []);
    }
}

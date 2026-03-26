<?php

namespace Tests\Unit;

use App\Services\PdfScannedPageClassifier;
use Tests\TestCase;

class PdfScannedPageClassifierTest extends TestCase
{
    public function test_it_marks_service_style_scanned_pages_as_non_body(): void
    {
        $classifier = app(PdfScannedPageClassifier::class);

        $trustedLines = [
            $this->line('DRAWN AND FILED BY:', 72.0, 250.0, 710.0),
            $this->line('V.A. NYAMODI & COMPANY', 72.0, 290.0, 686.0),
            $this->line('ADVOCATES', 72.0, 170.0, 662.0),
            $this->line('P.O BOX 51431-00200', 72.0, 235.0, 638.0),
            $this->line('NAIROBI', 72.0, 145.0, 614.0),
            $this->line('info@nyamodi.co.ke', 72.0, 220.0, 590.0),
            $this->line('Tel: (+254) (20) 2715542', 72.0, 255.0, 566.0),
            $this->line('COPIES TO BE SERVED UPON:', 72.0, 300.0, 500.0),
            $this->line('MOHAMMED MUIGAI, LLP', 72.0, 280.0, 476.0),
            $this->line('K-REP CENTRE, 4TH FLOOR', 72.0, 290.0, 452.0),
            $this->line('P.O. BOX 61323-00200', 72.0, 245.0, 428.0),
            $this->line('NAIROBI', 72.0, 145.0, 404.0),
        ];

        $result = $classifier->classify(
            ['engine' => 'ocr'],
            [
                'page_width' => 612.0,
                'page_height' => 792.0,
                'body_region' => ['left' => 64.0, 'right' => 318.0, 'top' => 722.0, 'bottom' => 392.0],
                'median_line_spacing' => 24.0,
                'multi_column_suspected' => false,
                'table_suspected' => false,
            ],
            $trustedLines,
            $this->anchors($trustedLines)
        );

        $this->assertFalse((bool) ($result['should_number'] ?? true));
        $this->assertSame('service_or_address_block', $result['type'] ?? null);
        $this->assertSame('address_or_service_pattern', $result['reason'] ?? null);
    }

    public function test_it_keeps_dense_scanned_body_pages_numberable(): void
    {
        $classifier = app(PdfScannedPageClassifier::class);

        $trustedLines = [];
        $y = 706.0;
        for ($index = 1; $index <= 18; $index++) {
            $trustedLines[] = $this->line(
                sprintf('The learned judge considered paragraph %02d of the pleading in detail.', $index),
                72.0,
                476.0,
                $y
            );
            $y -= 24.0;
        }

        $result = $classifier->classify(
            ['engine' => 'ocr'],
            [
                'page_width' => 612.0,
                'page_height' => 792.0,
                'body_region' => ['left' => 64.0, 'right' => 490.0, 'top' => 722.0, 'bottom' => 252.0],
                'median_line_spacing' => 24.0,
                'multi_column_suspected' => false,
                'table_suspected' => false,
            ],
            $trustedLines,
            $this->anchors($trustedLines)
        );

        $this->assertTrue((bool) ($result['should_number'] ?? false));
        $this->assertSame('body_text', $result['type'] ?? null);
    }

    public function test_it_keeps_scanned_pages_with_tables_numberable(): void
    {
        $classifier = app(PdfScannedPageClassifier::class);

        $trustedLines = [
            $this->line('Due Date Interest (US$) Monitoring Costs (US$)', 72.0, 470.0, 680.0),
            $this->line('31-Jan-2016 190,667.72 11,751.83', 72.0, 470.0, 652.0),
            $this->line('30-Apr-2016 189,804.82 11,496.36', 72.0, 470.0, 624.0),
            $this->line('31-Jul-2016 250,354.84 11,751.83', 72.0, 470.0, 596.0),
        ];

        $result = $classifier->classify(
            ['engine' => 'ocr'],
            [
                'page_width' => 612.0,
                'page_height' => 792.0,
                'body_region' => ['left' => 64.0, 'right' => 490.0, 'top' => 702.0, 'bottom' => 560.0],
                'median_line_spacing' => 28.0,
                'multi_column_suspected' => false,
                'table_suspected' => true,
                'table_row_count' => 4,
            ],
            $trustedLines,
            $this->anchors($trustedLines)
        );

        $this->assertTrue((bool) ($result['should_number'] ?? false));
        $this->assertSame('body_text_with_table', $result['type'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $text, float $xStart, float $xEnd, float $y): array
    {
        return [
            'text' => $text,
            'x_start' => $xStart,
            'x_end' => $xEnd,
            'y' => $y,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{y: float, x_start: float, x_end: float}>
     */
    private function anchors(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'y' => (float) ($line['y'] ?? 0.0),
            'x_start' => (float) ($line['x_start'] ?? 0.0),
            'x_end' => (float) ($line['x_end'] ?? 0.0),
        ], $lines);
    }
}

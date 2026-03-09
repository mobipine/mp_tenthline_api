<?php

namespace Tests\Feature;

use App\Services\PdfLineNumberService;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class PdfLineNumberServiceTest extends TestCase
{
    public function test_it_places_numbering_on_right_margin_when_right_selected(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
        ]);

        $inputPath = sys_get_temp_dir() . '/legalline_test_service_input_right_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/legalline_test_service_output_right_' . uniqid() . '.pdf';
        $this->createSamplePdf($inputPath, 28);

        $service = app(PdfLineNumberService::class);
        $pageCount = $service->addLineNumbers($inputPath, $outputPath, 10, 'right', 8);

        $this->assertSame(1, $pageCount);
        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath) ?: 0);

        $text = shell_exec(sprintf('pdftotext %s - 2>/dev/null', escapeshellarg($outputPath)));
        $text = is_string($text) ? $text : '';

        $this->assertStringContainsString('-10', $text);
        $this->assertStringContainsString('-20', $text);
        $this->assertLabelNearMargin($outputPath, 'right');

        @unlink($inputPath);
        @unlink($outputPath);
    }

    public function test_it_places_numbering_on_left_margin_when_left_selected(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
        ]);

        $inputPath = sys_get_temp_dir() . '/legalline_test_service_input_left_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/legalline_test_service_output_left_' . uniqid() . '.pdf';
        $this->createSamplePdf($inputPath, 28);

        $service = app(PdfLineNumberService::class);
        $pageCount = $service->addLineNumbers($inputPath, $outputPath, 10, 'left', 8);

        $this->assertSame(1, $pageCount);
        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath) ?: 0);

        $text = shell_exec(sprintf('pdftotext %s - 2>/dev/null', escapeshellarg($outputPath)));
        $text = is_string($text) ? $text : '';

        $this->assertStringContainsString('-10', $text);
        $this->assertStringContainsString('-20', $text);
        $this->assertLabelNearMargin($outputPath, 'left');

        @unlink($inputPath);
        @unlink($outputPath);
    }

    private function createSamplePdf(string $path, int $lineCount): void
    {
        $pdf = new Fpdi('P', 'pt');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);

        for ($line = 1; $line <= $lineCount; $line++) {
            $y = 72 + ($line * 20);
            $pdf->Text(72, $y, "Contract line {$line} content");
        }

        $pdf->Output('F', $path);
    }

    private function assertLabelNearMargin(string $pdfPath, string $margin): void
    {
        $bboxXml = shell_exec(sprintf('pdftotext -bbox-layout -enc UTF-8 %s - 2>/dev/null', escapeshellarg($pdfPath)));
        $bboxXml = is_string($bboxXml) ? $bboxXml : '';
        $this->assertNotSame('', trim($bboxXml), 'Could not extract bbox XML from generated PDF.');

        $pageWidth = $this->extractPageWidth($bboxXml);
        $this->assertGreaterThan(0.0, $pageWidth, 'Could not parse page width from bbox XML.');

        $labelBox = $this->extractWordBox($bboxXml, '-10');
        $this->assertNotNull($labelBox, 'Could not find "-10" label bbox in output PDF.');

        if ($margin === 'right') {
            $this->assertGreaterThan(
                $pageWidth * 0.90,
                $labelBox['xMin'],
                'Right-margin label is not close enough to the right page edge.'
            );

            return;
        }

        $this->assertLessThan(
            $pageWidth * 0.10,
            $labelBox['xMin'],
            'Left-margin label is not close enough to the left page edge.'
        );
    }

    private function extractPageWidth(string $bboxXml): float
    {
        if (preg_match('/<page[^>]*\\bwidth="([0-9.]+)"/i', $bboxXml, $match) !== 1) {
            return 0.0;
        }

        return (float) $match[1];
    }

    /**
     * @return array{xMin: float, xMax: float}|null
     */
    private function extractWordBox(string $bboxXml, string $word): ?array
    {
        $pattern = '/<word\\s+[^>]*xMin="([0-9.]+)"[^>]*xMax="([0-9.]+)"[^>]*>'
            . preg_quote($word, '/')
            . '<\\/word>/u';

        if (preg_match($pattern, $bboxXml, $match) !== 1) {
            return null;
        }

        return [
            'xMin' => (float) $match[1],
            'xMax' => (float) $match[2],
        ];
    }

    private function commandExists(string $command): bool
    {
        $path = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command)));

        return is_string($path) && trim($path) !== '';
    }
}

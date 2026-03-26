<?php

namespace Tests\Feature;

use App\Services\PdfLineNumberService;
use setasign\Fpdi\Fpdi;
use Tests\Concerns\CreatesLineNumberingPdfs;
use Tests\TestCase;

class PdfLineNumberServiceTest extends TestCase
{
    use CreatesLineNumberingPdfs;

    public function test_it_places_numbering_on_right_margin_when_right_selected(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => false,
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
            'line_numbering.enable_ocr_fallback' => false,
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

    public function test_it_aligns_labels_with_body_lines_even_when_headers_and_footers_repeat(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => false,
        ]);

        $inputPath = sys_get_temp_dir() . '/legalline_test_service_input_headers_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/legalline_test_service_output_headers_' . uniqid() . '.pdf';

        $this->createPdf($inputPath, [
            $this->legalPage($this->bodyLines('P1', 20), 'Case No. 100 of 2026', 'Advocates for the Plaintiff', 1),
            $this->legalPage($this->bodyLines('P2', 20), 'Case No. 101 of 2026', 'Advocates for the Plaintiff', 2),
        ]);

        $service = app(PdfLineNumberService::class);
        $service->addLineNumbers($inputPath, $outputPath, 10, 'right', 8);

        $bboxXml = $this->bboxXml($outputPath);
        $label10 = $this->findWordBox($bboxXml, 1, '-10');
        $line10 = $this->findWordBox($bboxXml, 1, 'P1L10');
        $label20 = $this->findWordBox($bboxXml, 1, '-20');
        $line20 = $this->findWordBox($bboxXml, 1, 'P1L20');

        $this->assertNotNull($label10);
        $this->assertNotNull($line10);
        $this->assertNotNull($label20);
        $this->assertNotNull($line20);
        $this->assertLessThanOrEqual(7.0, abs($this->wordMidY($label10) - $this->wordMidY($line10)));
        $this->assertLessThanOrEqual(7.0, abs($this->wordMidY($label20) - $this->wordMidY($line20)));

        @unlink($inputPath);
        @unlink($outputPath);
    }

    public function test_it_preserves_short_body_lines_in_the_count(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => false,
        ]);

        $inputPath = sys_get_temp_dir() . '/legalline_test_service_input_short_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/legalline_test_service_output_short_' . uniqid() . '.pdf';

        $bodyLines = $this->bodyLines('SHORT', 20);
        $bodyLines[9] = 'SHORT10';
        $this->createPdf($inputPath, [
            $this->legalPage($bodyLines, 'Case No. 100 of 2026', 'Advocates for the Plaintiff', 1),
            $this->legalPage($this->bodyLines('REF', 20), 'Case No. 101 of 2026', 'Advocates for the Plaintiff', 2),
        ]);

        $service = app(PdfLineNumberService::class);
        $service->addLineNumbers($inputPath, $outputPath, 10, 'left', 8);

        $bboxXml = $this->bboxXml($outputPath);
        $label10 = $this->findWordBox($bboxXml, 1, '-10');
        $shortLine = $this->findWordBox($bboxXml, 1, 'SHORT10');

        $this->assertNotNull($label10);
        $this->assertNotNull($shortLine);
        $this->assertLessThanOrEqual(7.0, abs($this->wordMidY($label10) - $this->wordMidY($shortLine)));

        @unlink($inputPath);
        @unlink($outputPath);
    }

    public function test_it_numbers_qpdf_normalized_pdfs_that_fpdi_cannot_open_directly(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        if (! $this->commandExists('qpdf')) {
            $this->markTestSkipped('qpdf command not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => false,
        ]);

        $basePath = sys_get_temp_dir() . '/legalline_test_service_input_qpdf_base_' . uniqid() . '.pdf';
        $inputPath = sys_get_temp_dir() . '/legalline_test_service_input_qpdf_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/legalline_test_service_output_qpdf_' . uniqid() . '.pdf';
        $this->createSamplePdf($basePath, 28);
        $this->createObjectStreamPdf($basePath, $inputPath);

        $this->assertFalse($this->canOpenWithFpdi($inputPath));

        $service = app(PdfLineNumberService::class);
        $pageCount = $service->addLineNumbers($inputPath, $outputPath, 10, 'right', 8);

        $this->assertSame(1, $pageCount);
        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath) ?: 0);

        $text = shell_exec(sprintf('pdftotext %s - 2>/dev/null', escapeshellarg($outputPath)));
        $text = is_string($text) ? $text : '';

        $this->assertStringContainsString('-10', $text);
        $this->assertStringContainsString('-20', $text);

        @unlink($basePath);
        @unlink($inputPath);
        @unlink($outputPath);
    }

    public function test_it_can_use_ocr_fallback_for_image_only_pages(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        if (! $this->commandExists('tesseract') || ! $this->commandExists('pdftoppm')) {
            $this->markTestSkipped('OCR commands are not available on this machine.');
        }

        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            $this->markTestSkipped('GD with TrueType font support is not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => true,
            'line_numbering.ocr_trigger_page_confidence' => 0.95,
        ]);

        $inputPath = sys_get_temp_dir() . '/legalline_test_service_input_ocr_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/legalline_test_service_output_ocr_' . uniqid() . '.pdf';
        $this->createImageOnlyPdf($inputPath, $this->bodyLines('OCR', 20));

        $service = app(PdfLineNumberService::class);
        $service->addLineNumbers($inputPath, $outputPath, 10, 'right', 8);

        $diagnostics = $service->getLastRunDiagnostics();
        $pageDiagnostics = $diagnostics['pages'][1]['diagnostics'] ?? [];
        $text = shell_exec(sprintf('pdftotext %s - 2>/dev/null', escapeshellarg($outputPath)));
        $text = is_string($text) ? $text : '';

        $this->assertSame('ocr', $pageDiagnostics['engine'] ?? null);
        $this->assertTrue((bool) ($pageDiagnostics['used_ocr_fallback'] ?? false));
        $this->assertGreaterThanOrEqual(2, (int) ($diagnostics['pages'][1]['labels_drawn'] ?? 0));
        $this->assertStringContainsString('-10', $text);

        @unlink($inputPath);
        @unlink($outputPath);
    }

    public function test_it_skips_non_body_scanned_pages_instead_of_drawing_a_fake_grid(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        if (! $this->commandExists('tesseract') || ! $this->commandExists('pdftoppm')) {
            $this->markTestSkipped('OCR commands are not available on this machine.');
        }

        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            $this->markTestSkipped('GD with TrueType font support is not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => true,
            'line_numbering.ocr_trigger_page_confidence' => 0.95,
            'line_numbering.low_confidence_page_strategy' => 'number',
        ]);

        $inputPath = sys_get_temp_dir() . '/legalline_test_service_input_ocr_service_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/legalline_test_service_output_ocr_service_' . uniqid() . '.pdf';
        $this->createImageOnlyPdf($inputPath, $this->serviceBlockLines());

        $service = app(PdfLineNumberService::class);
        $service->addLineNumbers($inputPath, $outputPath, 10, 'right', 8);

        $diagnostics = $service->getLastRunDiagnostics();
        $pageRun = $diagnostics['pages'][1] ?? [];
        $pageDiagnostics = $pageRun['diagnostics'] ?? [];
        $classification = $pageDiagnostics['scanned_page_classification'] ?? [];
        $text = shell_exec(sprintf('pdftotext %s - 2>/dev/null', escapeshellarg($outputPath)));
        $text = is_string($text) ? $text : '';

        $this->assertSame('ocr', $pageDiagnostics['engine'] ?? null);
        $this->assertTrue((bool) ($pageDiagnostics['used_ocr_fallback'] ?? false));
        $this->assertFalse((bool) ($classification['should_number'] ?? true));
        $this->assertSame('skip_scanned_non_body', $pageRun['placement_mode'] ?? null);
        $this->assertSame(0, (int) ($pageRun['labels_drawn'] ?? 0));
        $this->assertStringNotContainsString('-10', $text);

        @unlink($inputPath);
        @unlink($outputPath);
    }

    /**
     * @return list<string>
     */
    private function bodyLines(string $prefix, int $count): array
    {
        $lines = [];

        for ($line = 1; $line <= $count; $line++) {
            $lines[] = sprintf('%sL%02d body content', $prefix, $line);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function serviceBlockLines(): array
    {
        return [
            'DRAWN AND FILED BY:',
            'V.A. NYAMODI & COMPANY',
            'ADVOCATES',
            'HSE NO 7 DUPLEX APARTMENTS,',
            'LOWERHILL ROAD, UPPERHILL',
            'P.O BOX 51431-00200',
            'NAIROBI',
            'info@nyamodi.co.ke',
            'Tel: (+254) (20) 2715542',
            'COPIES TO BE SERVED UPON:',
            'MOHAMMED MUIGAI, LLP',
            'MM CHAMBERS',
            'K-REP CENTRE, 4TH FLOOR',
            'P.O. BOX 61323-00200',
            'NAIROBI',
            'info@mohammedmuigai.com',
            'Tel: 0722 851 641',
        ];
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

    /**
     * @param  list<string>  $lines
     */
    private function createImageOnlyPdf(string $path, array $lines): void
    {
        $imagePath = sys_get_temp_dir() . '/legalline_test_service_image_' . uniqid() . '.png';
        $image = imagecreatetruecolor(1275, 1650);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);

        $fontPath = '/System/Library/Fonts/Supplemental/Arial.ttf';
        $y = 140;
        foreach ($lines as $line) {
            imagettftext($image, 30, 0, 120, $y, $black, $fontPath, $line);
            $y += 62;
        }

        imagepng($image, $imagePath);
        imagedestroy($image);

        $pdf = new Fpdi('P', 'pt');
        $pdf->AddPage('P', [612.0, 792.0]);
        $pdf->Image($imagePath, 0, 0, 612.0, 792.0, 'PNG');
        $pdf->Output('F', $path);

        @unlink($imagePath);
    }

    private function assertLabelNearMargin(string $pdfPath, string $margin): void
    {
        $bboxXml = $this->bboxXml($pdfPath);
        $this->assertNotSame('', trim($bboxXml), 'Could not extract bbox XML from generated PDF.');

        if (preg_match('/<page[^>]*\\bwidth="([0-9.]+)"/i', $bboxXml, $match) !== 1) {
            $this->fail('Could not parse page width from bbox XML.');
        }

        $pageWidth = (float) $match[1];
        $labelBox = $this->findWordBox($bboxXml, 1, '-10');
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

    private function createObjectStreamPdf(string $inputPath, string $outputPath): void
    {
        $command = sprintf(
            '%s %s --object-streams=generate %s 2>&1',
            escapeshellcmd($this->commandPath('qpdf')),
            escapeshellarg($inputPath),
            escapeshellarg($outputPath)
        );

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertFileExists($outputPath);
    }

    private function canOpenWithFpdi(string $path): bool
    {
        try {
            $pdf = new Fpdi('P', 'pt');
            $pdf->setSourceFile($path);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

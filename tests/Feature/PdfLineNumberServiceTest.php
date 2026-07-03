<?php

namespace Tests\Feature;

use App\Services\PdfLineNumberService;
use App\Services\Scanned\PaddleOcrJobCoordinator;
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

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_right_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_right_' . uniqid() . '.pdf';
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

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_left_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_left_' . uniqid() . '.pdf';
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

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_headers_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_headers_' . uniqid() . '.pdf';

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

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_short_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_short_' . uniqid() . '.pdf';

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

    public function test_it_skips_numbering_on_pages_that_contain_tables(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => false,
            'line_numbering.skip_table_pages' => true,
            'paddleocr.enabled' => false,
        ]);

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_table_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_table_' . uniqid() . '.pdf';

        $this->createPdf($inputPath, [
            $this->tableHeavyPage(),
        ]);

        $service = app(PdfLineNumberService::class);
        $service->addLineNumbers($inputPath, $outputPath, 10, 'right', 8);

        $diagnostics = $service->getLastRunDiagnostics();
        $pageRun = $diagnostics['pages'][1] ?? [];
        $pageDiagnostics = $pageRun['diagnostics'] ?? [];
        $text = shell_exec(sprintf('pdftotext %s - 2>/dev/null', escapeshellarg($outputPath)));
        $text = is_string($text) ? $text : '';

        $this->assertTrue((bool) ($pageDiagnostics['table_suspected'] ?? false));
        $this->assertSame('skip_table_page', $pageRun['placement_mode'] ?? null);
        $this->assertSame(0, (int) ($pageRun['labels_drawn'] ?? 0));
        $this->assertStringNotContainsString('-10', $text);

        @unlink($inputPath);
        @unlink($outputPath);
    }

    public function test_it_counts_each_table_row_as_a_line_when_numbering_table_pages(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => false,
            'line_numbering.skip_table_pages' => false,
            'paddleocr.enabled' => false,
        ]);

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_table_rows_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_table_rows_' . uniqid() . '.pdf';

        $this->createPdf($inputPath, [[
            'texts' => [
                ['x' => 72.0, 'y' => 96.0, 'text' => 'Intro line 1 body text'],
                ['x' => 72.0, 'y' => 116.0, 'text' => 'Intro line 2 body text'],
                ['x' => 72.0, 'y' => 136.0, 'text' => 'Intro line 3 body text'],
                ['x' => 72.0, 'y' => 156.0, 'text' => 'Intro line 4 body text'],
                ['x' => 72.0, 'y' => 176.0, 'text' => 'Intro line 5 body text'],
                ['x' => 72.0, 'y' => 196.0, 'text' => 'Intro line 6 body text'],
                ['x' => 72.0, 'y' => 240.0, 'text' => 'Due Date'],
                ['x' => 228.0, 'y' => 240.0, 'text' => 'Interest (US$)'],
                ['x' => 372.0, 'y' => 240.0, 'text' => 'Monitoring Costs (US$)'],
                ['x' => 72.0, 'y' => 272.0, 'text' => '31-Jan-2016'],
                ['x' => 236.0, 'y' => 272.0, 'text' => '190,667.72'],
                ['x' => 394.0, 'y' => 272.0, 'text' => '11,751.83'],
                ['x' => 72.0, 'y' => 304.0, 'text' => '30-Apr-2016'],
                ['x' => 236.0, 'y' => 304.0, 'text' => '189,804.82'],
                ['x' => 394.0, 'y' => 304.0, 'text' => '11,496.36'],
                ['x' => 72.0, 'y' => 336.0, 'text' => '31-Jul-2016'],
                ['x' => 236.0, 'y' => 336.0, 'text' => '250,354.84'],
                ['x' => 394.0, 'y' => 336.0, 'text' => '11,751.83'],
            ],
            'font_size' => 12,
        ]]);

        $service = app(PdfLineNumberService::class);
        $service->addLineNumbers($inputPath, $outputPath, 10, 'right', 8);

        $diagnostics = $service->getLastRunDiagnostics();
        $pageRun = $diagnostics['pages'][1] ?? [];
        $pageDiagnostics = $pageRun['diagnostics'] ?? [];
        $bboxXml = $this->bboxXml($outputPath);
        $label10 = $this->findWordBox($bboxXml, 1, '-10');
        $tableValue = $this->findWordBox($bboxXml, 1, '250,354.84');

        $this->assertTrue((bool) ($pageDiagnostics['table_suspected'] ?? false));
        $this->assertSame(4, (int) ($pageDiagnostics['table_row_count'] ?? 0));
        $this->assertSame('trusted', $pageRun['placement_mode'] ?? null);
        $this->assertSame(1, (int) ($pageRun['labels_drawn'] ?? 0));
        $this->assertNotNull($label10);
        $this->assertNotNull($tableValue);
        $this->assertLessThanOrEqual(8.0, abs($this->wordMidY($label10) - $this->wordMidY($tableValue)));

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

        $basePath = sys_get_temp_dir() . '/tenthline_test_service_input_qpdf_base_' . uniqid() . '.pdf';
        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_qpdf_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_qpdf_' . uniqid() . '.pdf';
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

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_ocr_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_ocr_' . uniqid() . '.pdf';
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

    public function test_it_can_use_paddleocr_fallback_for_image_only_pages(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            $this->markTestSkipped('GD with TrueType font support is not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => false,
            'line_numbering.low_confidence_page_strategy' => 'number',
            'paddleocr.enabled' => true,
        ]);

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_paddleocr_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_paddleocr_' . uniqid() . '.pdf';
        $pdfJobId = 'paddleocr-test-job';
        $this->createImageOnlyPdf($inputPath, $this->bodyLines('TX', 20));

        $paddleOcr = \Mockery::mock(PaddleOcrJobCoordinator::class);
        $paddleOcr->shouldReceive('enabled')->atLeast()->once()->andReturnTrue();
        $paddleOcr->shouldReceive('resolvePageDimensions')->once()->with($inputPath)->andReturn([
            1 => ['width' => 612.0, 'height' => 792.0],
        ]);
        $paddleOcr->shouldReceive('run')->once()->with(
            $pdfJobId,
            $inputPath,
            \Mockery::type('array'),
            [1],
            \Mockery::on(static fn (mixed $options): bool => is_array($options))
        )->andReturn([
            'status' => 'SUCCEEDED',
            'job_id' => 'paddleocr-job-123',
            'result_path' => 'pdf-jobs/' . $pdfJobId . '/ocr-results.json',
            'started_at' => now()->subSeconds(2)->toISOString(),
            'completed_at' => now()->toISOString(),
            'pages_attempted' => [1],
            'warnings' => [],
            'pages' => [
                1 => $this->paddleOcrNormalizedPage($this->bodyLines('TX', 20)),
            ],
        ]);
        $this->app->instance(PaddleOcrJobCoordinator::class, $paddleOcr);

        $service = app(PdfLineNumberService::class);
        $service->addLineNumbers($inputPath, $outputPath, 10, 'right', 8, null, ['pdf_job_id' => $pdfJobId]);

        $diagnostics = $service->getLastRunDiagnostics();
        $pageRun = $diagnostics['pages'][1] ?? [];
        $pageDiagnostics = $pageRun['diagnostics'] ?? [];
        $ocrSummary = $diagnostics['extractor_summary']['ocr'] ?? [];
        $text = shell_exec(sprintf('pdftotext %s - 2>/dev/null', escapeshellarg($outputPath)));
        $text = is_string($text) ? $text : '';

        $this->assertSame('ocr', $pageDiagnostics['engine'] ?? null);
        $this->assertSame('paddleocr', $pageDiagnostics['ocr_provider'] ?? null);
        $this->assertTrue((bool) ($pageDiagnostics['used_ocr_fallback'] ?? false));
        $this->assertContains('paddleocr', $ocrSummary['providers_used'] ?? []);
        $this->assertSame('paddleocr-job-123', $ocrSummary['paddleocr']['job_id'] ?? null);
        $this->assertContains(1, $ocrSummary['pages_replaced'] ?? []);
        $this->assertGreaterThanOrEqual(2, (int) ($pageRun['labels_drawn'] ?? 0));
        $this->assertStringContainsString('-10', $text);

        @unlink($inputPath);
        @unlink($outputPath);
    }

    public function test_it_uses_paddleocr_for_image_based_pages_even_when_a_hidden_text_layer_exists(): void
    {
        if (! $this->commandExists('pdftotext') || ! $this->commandExists('pdfimages')) {
            $this->markTestSkipped('Poppler text and image inspection commands are not available on this machine.');
        }

        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            $this->markTestSkipped('GD with TrueType font support is not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.debug_overlay' => false,
            'line_numbering.enable_diagnostics' => true,
            'line_numbering.enable_ocr_fallback' => false,
            'paddleocr.enabled' => true,
        ]);

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_hidden_text_scan_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_hidden_text_scan_' . uniqid() . '.pdf';
        $pdfJobId = 'paddleocr-hidden-text-job';
        $lines = $this->bodyLines('SCAN', 20);
        $this->createScannedLookingPdfWithHiddenTextLayer($inputPath, $lines);

        $paddleOcr = \Mockery::mock(PaddleOcrJobCoordinator::class);
        $paddleOcr->shouldReceive('enabled')->atLeast()->once()->andReturnTrue();
        $paddleOcr->shouldReceive('resolvePageDimensions')->once()->with($inputPath)->andReturn([
            1 => ['width' => 612.0, 'height' => 792.0],
        ]);
        $paddleOcr->shouldReceive('run')->once()->with(
            $pdfJobId,
            $inputPath,
            \Mockery::type('array'),
            [1],
            \Mockery::type('array')
        )->andReturn([
            'status' => 'SUCCEEDED',
            'job_id' => 'paddleocr-job-hidden-layer',
            'result_path' => 'pdf-jobs/' . $pdfJobId . '/ocr-results.json',
            'started_at' => now()->subSeconds(2)->toISOString(),
            'completed_at' => now()->toISOString(),
            'pages_attempted' => [1],
            'warnings' => [],
            'pages' => [
                1 => $this->paddleOcrNormalizedPage($lines),
            ],
        ]);
        $this->app->instance(PaddleOcrJobCoordinator::class, $paddleOcr);

        $service = app(PdfLineNumberService::class);
        $service->addLineNumbers($inputPath, $outputPath, 10, 'right', 8, null, ['pdf_job_id' => $pdfJobId]);

        $diagnostics = $service->getLastRunDiagnostics();
        $pageRun = $diagnostics['pages'][1] ?? [];
        $pageDiagnostics = $pageRun['diagnostics'] ?? [];
        $ocrSummary = $diagnostics['extractor_summary']['ocr'] ?? [];
        $candidateDetails = $ocrSummary['candidate_details'][1] ?? [];
        $imageMetrics = $pageDiagnostics['image_based_page_metrics'] ?? [];

        $this->assertTrue((bool) ($pageDiagnostics['used_ocr_fallback'] ?? false));
        $this->assertSame('paddleocr', $pageDiagnostics['ocr_provider'] ?? null);
        $this->assertSame('page_is_mostly_image_based', $candidateDetails['reason'] ?? null);
        $this->assertTrue((bool) ($candidateDetails['image_based_page'] ?? false));
        $this->assertTrue((bool) ($imageMetrics['is_mostly_image_based'] ?? false));
        $this->assertContains(1, $ocrSummary['pages_replaced'] ?? []);

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

        $inputPath = sys_get_temp_dir() . '/tenthline_test_service_input_ocr_service_' . uniqid() . '.pdf';
        $outputPath = sys_get_temp_dir() . '/tenthline_test_service_output_ocr_service_' . uniqid() . '.pdf';
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
     * @return array<string, mixed>
     */
    private function tableHeavyPage(): array
    {
        return [
            'texts' => [
                ['x' => 72.0, 'y' => 96.0, 'text' => 'The respondent relied on the following schedule:'],
                ['x' => 72.0, 'y' => 126.0, 'text' => 'The table below summarizes the amounts in issue.'],
                ['x' => 72.0, 'y' => 208.0, 'text' => 'Due Date'],
                ['x' => 228.0, 'y' => 208.0, 'text' => 'Interest (US$)'],
                ['x' => 372.0, 'y' => 208.0, 'text' => 'Monitoring Costs (US$)'],
                ['x' => 72.0, 'y' => 240.0, 'text' => '31-Jan-2016'],
                ['x' => 236.0, 'y' => 240.0, 'text' => '190,667.72'],
                ['x' => 394.0, 'y' => 240.0, 'text' => '11,751.83'],
                ['x' => 72.0, 'y' => 272.0, 'text' => '30-Apr-2016'],
                ['x' => 236.0, 'y' => 272.0, 'text' => '189,804.82'],
                ['x' => 394.0, 'y' => 272.0, 'text' => '11,496.36'],
                ['x' => 72.0, 'y' => 304.0, 'text' => '31-Jul-2016'],
                ['x' => 236.0, 'y' => 304.0, 'text' => '250,354.84'],
                ['x' => 394.0, 'y' => 304.0, 'text' => '11,751.83'],
                ['x' => 72.0, 'y' => 336.0, 'text' => '31-Oct-2016'],
                ['x' => 236.0, 'y' => 336.0, 'text' => '252,781.85'],
                ['x' => 394.0, 'y' => 336.0, 'text' => '11,751.83'],
                ['x' => 72.0, 'y' => 396.0, 'text' => 'The parties disputed whether the schedule had been fully reconciled.'],
            ],
            'font_size' => 12,
        ];
    }

    /**
     * @param  list<string>  $lines
     */
    private function createImageOnlyPdf(string $path, array $lines): void
    {
        $imagePath = sys_get_temp_dir() . '/tenthline_test_service_image_' . uniqid() . '.png';
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

    /**
     * @param  list<string>  $lines
     */
    private function createScannedLookingPdfWithHiddenTextLayer(string $path, array $lines): void
    {
        $imagePath = sys_get_temp_dir() . '/tenthline_test_service_hidden_text_image_' . uniqid() . '.png';
        $image = imagecreatetruecolor(1275, 1650);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);

        $fontPath = '/System/Library/Fonts/Supplemental/Arial.ttf';
        $imageY = 140;
        foreach ($lines as $line) {
            imagettftext($image, 30, 0, 120, $imageY, $black, $fontPath, $line);
            $imageY += 62;
        }

        imagepng($image, $imagePath);
        imagedestroy($image);

        $pdf = new Fpdi('P', 'pt');
        $pdf->AddPage('P', [612.0, 792.0]);
        $pdf->SetFont('Helvetica', '', 12);

        $textY = 120.0;
        foreach ($lines as $line) {
            $pdf->Text(72.0, $textY, $line);
            $textY += 24.0;
        }

        // Keep the text layer in the PDF, but visually cover it with a page-sized image.
        $pdf->Image($imagePath, 0, 0, 612.0, 792.0, 'PNG');
        $pdf->Output('F', $path);

        @unlink($imagePath);
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private function paddleOcrNormalizedPage(array $lines): array
    {
        $rawLines = [];
        $baseline = 660.0;

        foreach ($lines as $index => $text) {
            $y = $baseline - ($index * 20.0);
            $words = preg_split('/\s+/', trim($text)) ?: [];
            $resolvedWords = [];
            $cursor = 72.0;

            foreach ($words as $wordIndex => $word) {
                $width = max(20.0, strlen($word) * 5.8);
                $resolvedWords[] = [
                    'id' => sprintf('po-word-%d-%d', $index + 1, $wordIndex + 1),
                    'text' => $word,
                    'x_start' => round($cursor, 3),
                    'x_end' => round($cursor + $width, 3),
                    'y' => round($y, 3),
                    'top' => round($y + 7.5, 3),
                    'bottom' => round($y - 7.5, 3),
                    'height' => 15.0,
                    'confidence' => 99.1,
                    'source' => 'paddleocr',
                ];
                $cursor += $width + 9.0;
            }

            $rawLines[] = [
                'id' => sprintf('po-line-%d', $index + 1),
                'text' => $text,
                'x_start' => 72.0,
                'x_end' => 472.0,
                'y' => round($y, 3),
                'top' => round($y + 7.5, 3),
                'bottom' => round($y - 7.5, 3),
                'height' => 15.0,
                'char_count' => strlen($text),
                'words' => $resolvedWords,
                'source' => 'paddleocr',
                'confidence' => 99.1,
            ];
        }

        return [
            'page_no' => 1,
            'engine' => 'ocr',
            'ocr_provider' => 'paddleocr',
            'page_width' => 612.0,
            'page_height' => 792.0,
            'page_rotation' => 0,
            'raw_lines' => $rawLines,
            'diagnostic' => [
                'engine' => 'ocr',
                'ocr_provider' => 'paddleocr',
                'raw_lines_detected' => count($rawLines),
                'paddleocr_lines_retained' => count($rawLines),
            ],
        ];
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

<?php

namespace Tests\Unit;

use App\Services\PdfLineExtractor;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class PdfLineExtractorTest extends TestCase
{
    public function test_it_extracts_line_anchors_with_poppler_engine(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        config(['line_numbering.extractor_engine' => 'poppler']);

        $inputPath = sys_get_temp_dir() . '/legalline_test_extractor_' . uniqid() . '.pdf';
        $this->createSamplePdf($inputPath, 26);

        $extractor = app(PdfLineExtractor::class);
        $anchorsPerPage = $extractor->getLineAnchorsPerPage($inputPath);
        $diagnostics = $extractor->getLastDiagnostics();

        $this->assertNotEmpty($anchorsPerPage);
        $this->assertArrayHasKey(1, $anchorsPerPage);
        $this->assertGreaterThanOrEqual(20, count($anchorsPerPage[1]));
        $this->assertSame('poppler', $diagnostics['engine_used']);

        // Lines should be ordered top-to-bottom by Y in PDF coordinates.
        $first = $anchorsPerPage[1][0]['y'] ?? 0.0;
        $last = $anchorsPerPage[1][count($anchorsPerPage[1]) - 1]['y'] ?? 0.0;
        $this->assertGreaterThan($last, $first);

        @unlink($inputPath);
    }

    private function createSamplePdf(string $path, int $lineCount): void
    {
        $pdf = new Fpdi('P', 'pt');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);

        for ($line = 1; $line <= $lineCount; $line++) {
            $y = 72 + ($line * 20);
            $pdf->Text(72, $y, "Sample line {$line} text for extraction");
        }

        $pdf->Output('F', $path);
    }

    private function commandExists(string $command): bool
    {
        $path = shell_exec(sprintf('command -v %s 2>/dev/null', escapeshellarg($command)));

        return is_string($path) && trim($path) !== '';
    }
}

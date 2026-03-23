<?php

namespace Tests\Unit;

use App\Services\PdfLineExtractor;
use Tests\Concerns\CreatesLineNumberingPdfs;
use Tests\TestCase;

class PdfLineExtractorTest extends TestCase
{
    use CreatesLineNumberingPdfs;

    public function test_it_extracts_trusted_body_lines_and_suppresses_repeated_headers_and_footers(): void
    {
        if (! $this->commandExists('pdftotext')) {
            $this->markTestSkipped('pdftotext command not available on this machine.');
        }

        config([
            'line_numbering.extractor_engine' => 'poppler',
            'line_numbering.enable_ocr_fallback' => false,
        ]);

        $inputPath = sys_get_temp_dir() . '/legalline_test_extractor_' . uniqid() . '.pdf';
        $this->createPdf($inputPath, [
            $this->legalPage($this->bodyLines('P1', 20), 'Case No. 100 of 2026', 'Advocates for the Plaintiff', 1),
            $this->legalPage($this->bodyLines('P2', 20), 'Case No. 101 of 2026', 'Advocates for the Plaintiff', 2),
        ]);

        $extractor = app(PdfLineExtractor::class);
        $anchorsPerPage = $extractor->getLineAnchorsPerPage($inputPath);
        $diagnostics = $extractor->getLastDiagnostics();

        $this->assertSame('poppler', $diagnostics['engine_used']);
        $this->assertCount(20, $anchorsPerPage[1]);
        $this->assertCount(20, $anchorsPerPage[2]);
        $this->assertGreaterThanOrEqual(1, $diagnostics['pages'][1]['suppressed_headers']);
        $this->assertGreaterThanOrEqual(1, $diagnostics['pages'][1]['suppressed_footers']);
        $this->assertGreaterThan(0.58, $diagnostics['pages'][1]['page_confidence']);

        $first = $anchorsPerPage[1][0]['y'] ?? 0.0;
        $last = $anchorsPerPage[1][count($anchorsPerPage[1]) - 1]['y'] ?? 0.0;
        $this->assertGreaterThan($last, $first);

        @unlink($inputPath);
    }

    /**
     * @return list<string>
     */
    private function bodyLines(string $prefix, int $count): array
    {
        $lines = [];

        for ($line = 1; $line <= $count; $line++) {
            $lines[] = sprintf('%sL%02d body text for extraction', $prefix, $line);
        }

        return $lines;
    }
}

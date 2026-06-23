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

        $inputPath = sys_get_temp_dir() . '/tenthline_test_extractor_' . uniqid() . '.pdf';
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

    public function test_it_skips_whole_document_textract_for_a_single_minor_candidate_page_in_a_large_pdf(): void
    {
        $extractor = app(PdfLineExtractor::class);
        $method = new \ReflectionMethod($extractor, 'textractEligibilityDecision');
        $method->setAccessible(true);

        $pages = [];
        for ($pageNo = 1; $pageNo <= 193; $pageNo++) {
            $pages[$pageNo] = ['page_no' => $pageNo];
        }

        $decision = $method->invoke($extractor, $pages, [
            168 => [
                'reason' => 'too_few_trusted_anchors',
                'page_confidence' => 0.7555,
                'trusted_anchor_count' => 2,
                'raw_line_count' => 4,
            ],
        ]);

        $this->assertFalse($decision['should_use']);
        $this->assertSame('candidate_pages_do_not_justify_whole_document_textract', $decision['reason']);
    }

    public function test_it_uses_textract_for_a_document_that_has_no_extractable_lines(): void
    {
        $extractor = app(PdfLineExtractor::class);
        $method = new \ReflectionMethod($extractor, 'textractEligibilityDecision');
        $method->setAccessible(true);

        $decision = $method->invoke($extractor, [
            1 => ['page_no' => 1],
        ], [
            1 => [
                'reason' => 'no_extractable_lines',
                'page_confidence' => 0.0,
                'trusted_anchor_count' => 0,
                'raw_line_count' => 0,
            ],
        ]);

        $this->assertTrue($decision['should_use']);
        $this->assertSame('document_looks_scanned_or_ocr_dependent', $decision['reason']);
    }

    public function test_it_uses_textract_for_pages_that_are_mostly_image_based(): void
    {
        $extractor = app(PdfLineExtractor::class);
        $method = new \ReflectionMethod($extractor, 'textractEligibilityDecision');
        $method->setAccessible(true);

        $decision = $method->invoke($extractor, [
            1 => ['page_no' => 1],
            2 => ['page_no' => 2],
        ], [
            1 => [
                'reason' => 'page_is_mostly_image_based',
                'page_confidence' => 0.81,
                'trusted_anchor_count' => 22,
                'raw_line_count' => 25,
            ],
            2 => [
                'reason' => 'page_is_mostly_image_based',
                'page_confidence' => 0.79,
                'trusted_anchor_count' => 21,
                'raw_line_count' => 24,
            ],
        ]);

        $this->assertTrue($decision['should_use']);
        $this->assertSame('document_looks_scanned_or_ocr_dependent', $decision['reason']);
        $this->assertSame([1, 2], $decision['image_based_pages']);
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

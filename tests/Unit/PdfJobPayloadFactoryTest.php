<?php

namespace Tests\Unit;

use App\Support\PdfJobPayloadFactory;
use Tests\TestCase;

class PdfJobPayloadFactoryTest extends TestCase
{
    public function test_it_surfaces_live_processing_state_from_ocr_diagnostics(): void
    {
        $payload = PdfJobPayloadFactory::withProcessingState([
            'id' => 'job-123',
            'status' => 'processing',
            'progress' => 22,
            'processed_pages' => 0,
            'total_pages' => 2,
            'eta_seconds' => null,
            'error_message' => null,
            'download_url' => null,
        ], [
            'live' => [
                'phase' => 'ocr_polling',
                'label' => 'Reading the page text carefully',
                'message' => 'We are still reading the page text carefully.',
                'detail' => 'Still working through the document.',
            ],
        ]);

        $this->assertSame('ocr_polling', $payload['processing_stage']);
        $this->assertSame('Reading the page text carefully', $payload['processing_label']);
        $this->assertSame('We are still reading the page text carefully.', $payload['processing_message']);
        $this->assertSame('Still working through the document.', $payload['processing_detail']);
    }

    public function test_it_falls_back_to_analyzing_state_before_page_progress_exists(): void
    {
        $payload = PdfJobPayloadFactory::withProcessingState([
            'id' => 'job-456',
            'status' => 'processing',
            'progress' => 3,
            'processed_pages' => 0,
            'total_pages' => 5,
            'eta_seconds' => null,
            'error_message' => null,
            'download_url' => null,
        ]);

        $this->assertSame('analyzing_document', $payload['processing_stage']);
        $this->assertSame('Analyzing document', $payload['processing_label']);
        $this->assertSame('5 pages detected', $payload['processing_detail']);
    }
}

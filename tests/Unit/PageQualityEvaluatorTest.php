<?php

namespace Tests\Unit;

use App\Enums\PageStatus;
use App\Services\Quality\PageQualityEvaluator;
use App\Services\Quality\PageQualityResult;
use App\Settings\OcrQualitySettings;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class PageQualityEvaluatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeSettings(array $overrides = []): OcrQualitySettings
    {
        /** @var OcrQualitySettings&MockInterface $settings */
        $settings = Mockery::mock(OcrQualitySettings::class)->makePartial();
        $settings->min_ocr_confidence = $overrides['min_ocr_confidence'] ?? 0.6;
        $settings->min_text_boxes = $overrides['min_text_boxes'] ?? 3;
        $settings->min_extracted_chars = $overrides['min_extracted_chars'] ?? 10;
        $settings->min_page_coverage_pct = $overrides['min_page_coverage_pct'] ?? 0.05;
        $settings->bill_low_confidence_pages = $overrides['bill_low_confidence_pages'] ?? false;
        return $settings;
    }

    private function makeEvaluator(array $settingOverrides = []): PageQualityEvaluator
    {
        return new PageQualityEvaluator($this->makeSettings($settingOverrides));
    }

    /**
     * Build a page-run data array as PageQualityEvaluator expects it from PdfLineNumberService.
     * Metrics come from diagnostics.scored_lines, not from top-level fields.
     *
     * @param  array<array{text:string,confidence:float,char_count:int,x_start:float,x_end:float,height:float,y:float}>  $lines
     */
    private function ocrPageData(
        ?array $lines = null,
        string $placementMode = 'ocr_anchor',
        int $labelsDrawn = 10,
    ): array {
        if ($lines === null) {
            // Default: 5 high-confidence lines
            $lines = array_fill(0, 5, [
                'text' => 'Lorem ipsum dolor',
                'confidence' => 0.9,
                'char_count' => 17,
                'x_start' => 72.0,
                'x_end' => 540.0,
                'height' => 14.0,
                'y' => 100.0,
            ]);
        }

        return [
            'placement_mode' => $placementMode,
            'labels_drawn' => $labelsDrawn,
            'diagnostics' => [
                'engine' => 'ocr',
                'page_width' => 612.0,
                'page_height' => 792.0,
                'scored_lines' => $lines,
            ],
        ];
    }

    private function textPageData(string $confidenceLabel = 'high', bool $lowConfidence = false): array
    {
        return [
            'placement_mode' => 'text_native',
            'labels_drawn' => 10,
            'low_confidence' => $lowConfidence,
            'diagnostics' => [
                'engine' => 'text',
                'page_confidence_label' => $confidenceLabel,
            ],
        ];
    }

    private function makeLines(float $confidence, int $count = 1, int $charsEach = 10): array
    {
        return array_fill(0, $count, [
            'text' => str_repeat('a', $charsEach),
            'confidence' => $confidence,
            'char_count' => $charsEach,
            'x_start' => 72.0,
            'x_end' => 540.0,
            'height' => 14.0,
            'y' => 100.0,
        ]);
    }

    public function test_high_confidence_ocr_page_is_success(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData());

        $this->assertSame(PageStatus::Success, $result->status);
        $this->assertTrue($result->isBillable);
    }

    public function test_low_confidence_ocr_page_is_low_confidence(): void
    {
        // 5 lines but each is low confidence (0.4) and has enough chars — partial content
        $lines = $this->makeLines(0.4, 5, 12);
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData($lines));

        $this->assertSame(PageStatus::LowConfidence, $result->status);
    }

    public function test_too_few_text_boxes_is_failed(): void
    {
        // Empty scored_lines → no content at all → Failed
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData([]));

        $this->assertSame(PageStatus::Failed, $result->status);
        $this->assertFalse($result->isBillable);
    }

    public function test_low_confidence_page_is_billable_when_setting_enabled(): void
    {
        $lines = $this->makeLines(0.4, 5, 12);
        $result = $this->makeEvaluator(['bill_low_confidence_pages' => true])
            ->evaluatePage(1, $this->ocrPageData($lines));

        $this->assertSame(PageStatus::LowConfidence, $result->status);
        $this->assertTrue($result->isBillable);
    }

    public function test_low_confidence_page_is_not_billable_when_setting_disabled(): void
    {
        $lines = $this->makeLines(0.4, 5, 12);
        $result = $this->makeEvaluator(['bill_low_confidence_pages' => false])
            ->evaluatePage(1, $this->ocrPageData($lines));

        $this->assertSame(PageStatus::LowConfidence, $result->status);
        $this->assertFalse($result->isBillable);
    }

    public function test_text_native_page_with_high_confidence_label_is_success(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->textPageData('high'));

        $this->assertSame(PageStatus::Success, $result->status);
    }

    public function test_text_native_page_with_low_label_is_low_confidence(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->textPageData('low', true));

        $this->assertSame(PageStatus::LowConfidence, $result->status);
    }

    public function test_skip_placement_mode_is_failed_and_not_billable(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData(null, 'skip_table_page'));

        $this->assertSame(PageStatus::Failed, $result->status);
        $this->assertFalse($result->isBillable);
    }

    public function test_evaluate_all_returns_result_per_page(): void
    {
        $runPages = [
            1 => $this->ocrPageData(),
            2 => $this->ocrPageData([]),  // no scored_lines → no content → Failed
        ];

        $results = $this->makeEvaluator()->evaluateAll($runPages);

        $this->assertCount(2, $results);
        $this->assertContainsOnlyInstancesOf(PageQualityResult::class, $results);
        $this->assertSame(PageStatus::Success, $results[0]->status);
        $this->assertSame(PageStatus::Failed, $results[1]->status);
    }

    public function test_page_quality_result_to_array_has_all_required_keys(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData());
        $arr = $result->toArray();

        foreach (['page_number', 'status', 'ocr_confidence', 'text_box_count', 'extracted_chars', 'page_coverage_pct', 'placement_mode', 'line_labels_applied', 'is_billable'] as $key) {
            $this->assertArrayHasKey($key, $arr, "Missing array key: $key");
        }
    }
}

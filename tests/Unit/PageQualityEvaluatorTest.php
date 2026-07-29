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

    private function ocrPageData(array $overrides = []): array
    {
        return array_merge([
            'placement_mode' => 'ocr_anchor',
            'line_labels_applied' => 10,
            'ocr_confidence' => 0.85,
            'text_box_count' => 5,
            'extracted_chars' => 50,
            'page_coverage_pct' => 0.25,
            'page_confidence_label' => null,
        ], $overrides);
    }

    public function test_high_confidence_ocr_page_is_success(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData());

        $this->assertSame(PageStatus::Success, $result->status);
        $this->assertTrue($result->isBillable);
    }

    public function test_low_confidence_ocr_page_is_low_confidence(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData([
            'ocr_confidence' => 0.4,
        ]));

        $this->assertSame(PageStatus::LowConfidence, $result->status);
    }

    public function test_too_few_text_boxes_is_failed(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData([
            'text_box_count' => 1,
            'extracted_chars' => 2,
            'page_coverage_pct' => 0.01,
        ]));

        $this->assertSame(PageStatus::Failed, $result->status);
        $this->assertFalse($result->isBillable);
    }

    public function test_low_confidence_page_is_billable_when_setting_enabled(): void
    {
        $result = $this->makeEvaluator(['bill_low_confidence_pages' => true])
            ->evaluatePage(1, $this->ocrPageData(['ocr_confidence' => 0.4]));

        $this->assertSame(PageStatus::LowConfidence, $result->status);
        $this->assertTrue($result->isBillable);
    }

    public function test_low_confidence_page_is_not_billable_when_setting_disabled(): void
    {
        $result = $this->makeEvaluator(['bill_low_confidence_pages' => false])
            ->evaluatePage(1, $this->ocrPageData(['ocr_confidence' => 0.4]));

        $this->assertSame(PageStatus::LowConfidence, $result->status);
        $this->assertFalse($result->isBillable);
    }

    public function test_text_native_page_with_high_confidence_label_is_success(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData([
            'placement_mode' => 'text_native',
            'page_confidence_label' => 'high',
        ]));

        $this->assertSame(PageStatus::Success, $result->status);
    }

    public function test_text_native_page_with_low_label_is_low_confidence(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData([
            'placement_mode' => 'text_native',
            'page_confidence_label' => 'low',
        ]));

        $this->assertSame(PageStatus::LowConfidence, $result->status);
    }

    public function test_skip_placement_mode_is_failed_and_not_billable(): void
    {
        $result = $this->makeEvaluator()->evaluatePage(1, $this->ocrPageData([
            'placement_mode' => 'skipped_empty',
        ]));

        $this->assertSame(PageStatus::Failed, $result->status);
        $this->assertFalse($result->isBillable);
    }

    public function test_evaluate_all_returns_result_per_page(): void
    {
        $runPages = [
            1 => $this->ocrPageData(),
            2 => $this->ocrPageData(['ocr_confidence' => 0.3, 'text_box_count' => 1, 'extracted_chars' => 2, 'page_coverage_pct' => 0.01]),
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

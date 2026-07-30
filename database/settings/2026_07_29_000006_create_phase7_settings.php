<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('retention', function ($blueprint): void {
            $blueprint->add('retention_value', 24);
            $blueprint->add('retention_unit', 'hours');
            $blueprint->add('support_attachment_retention_hours', 24);
            $blueprint->add('payment_deadline_hours', 48);
        });

        $this->migrator->inGroup('ocr_quality', function ($blueprint): void {
            $blueprint->add('min_ocr_confidence', 0.6);
            $blueprint->add('min_text_boxes', 3);
            $blueprint->add('min_extracted_chars', 10);
            $blueprint->add('min_page_coverage_pct', 0.05);
            $blueprint->add('bill_low_confidence_pages', false);
        });

        $this->migrator->inGroup('legal', function ($blueprint): void {
            $blueprint->add('terms_and_conditions', null);
            $blueprint->add('privacy_policy', null);
            $blueprint->add('terms_updated_at', null);
            $blueprint->add('privacy_updated_at', null);
        });
    }
};

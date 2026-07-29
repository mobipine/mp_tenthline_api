<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedGroup('app', [
            'enable_payment' => true,
            'price_per_page' => 5.0,
            'currency' => 'KES',
            'max_file_size_mb' => 50,
            'max_pages' => 500,
        ]);

        $this->seedGroup('retention', [
            'retention_value' => 24,
            'retention_unit' => 'hours',
            'support_attachment_retention_hours' => 24,
            'payment_deadline_hours' => 48,
        ]);

        $this->seedGroup('ocr_quality', [
            'min_ocr_confidence' => 0.6,
            'min_text_boxes' => 3,
            'min_extracted_chars' => 10,
            'min_page_coverage_pct' => 0.05,
            'bill_low_confidence_pages' => false,
        ]);

        $this->seedGroup('legal', [
            'terms_and_conditions' => null,
            'privacy_policy' => null,
            'terms_updated_at' => null,
            'privacy_updated_at' => null,
        ]);
    }

    private function seedGroup(string $group, array $settings): void
    {
        foreach ($settings as $name => $value) {
            DB::table('settings')->updateOrInsert(
                ['group' => $group, 'name' => $name],
                ['payload' => json_encode($value), 'locked' => false]
            );
        }
    }
}

<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('app', function (\Spatie\LaravelSettings\Migrations\SettingsBlueprint $blueprint): void {
            $blueprint->add('enable_payment', false);
            $blueprint->add('price_per_document', 100.0);
            $blueprint->add('currency', 'KES');
            $blueprint->add('max_file_size_mb', 500);
            $blueprint->add('max_pages', 3000);
        });
    }
};

<?php

namespace App\Settings;

use App\Enums\RetentionUnit;
use Spatie\LaravelSettings\Settings;

class RetentionSettings extends Settings
{
    public int $retention_value;
    public string $retention_unit;

    /** Hours to keep support ticket attachments after ticket resolution */
    public int $support_attachment_retention_hours;

    /** Hours to keep a processed-but-unpaid job before purging */
    public int $payment_deadline_hours;

    public static function group(): string
    {
        return 'retention';
    }

    public function retentionInHours(): int
    {
        $unit = RetentionUnit::tryFrom($this->retention_unit) ?? RetentionUnit::Hours;

        return $unit->toHours($this->retention_value);
    }
}

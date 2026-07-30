<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class LegalContentSettings extends Settings
{
    public ?string $terms_and_conditions;
    public ?string $privacy_policy;

    public ?string $terms_updated_at;
    public ?string $privacy_updated_at;

    public static function group(): string
    {
        return 'legal';
    }
}

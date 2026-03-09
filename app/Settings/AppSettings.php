<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AppSettings extends Settings
{
    public bool $enable_payment;
    public float $price_per_page;
    public string $currency;
    public int $max_file_size_mb;
    public int $max_pages;

    public static function group(): string
    {
        return 'app';
    }
}

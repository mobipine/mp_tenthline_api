<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retention-driven purge — reads period from RetentionSettings (no CLI arg needed)
Schedule::command('pdf-jobs:purge-expired')->hourly();

// Purge output PDFs for jobs that were processed but never paid within the deadline
Schedule::command('pdf-jobs:purge-unpaid')->hourly();

// Delete support ticket attachments 24h after ticket resolution
Schedule::command('support:purge-attachments')->hourly();

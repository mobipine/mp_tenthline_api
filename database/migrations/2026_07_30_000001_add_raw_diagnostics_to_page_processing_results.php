<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_processing_results', function (Blueprint $table): void {
            // Full per-page diagnostic snapshot from PdfLineNumberService / PdfLineExtractor.
            // Contains: engine, scored_lines (per-box confidence/text/coords), page dimensions,
            // scanned_page_classification, and any other extractor data.
            // Purged automatically when the parent ProcessingReport / PdfJob is deleted.
            $table->json('raw_diagnostics')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('page_processing_results', function (Blueprint $table): void {
            $table->dropColumn('raw_diagnostics');
        });
    }
};

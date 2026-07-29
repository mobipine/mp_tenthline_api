<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_processing_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('processing_report_id')->constrained('processing_reports')->cascadeOnDelete();

            $table->unsignedInteger('page_number');
            // success | low_confidence | failed
            $table->string('status', 20);

            // OCR quality metrics
            $table->decimal('ocr_confidence', 5, 4)->nullable();
            $table->unsignedInteger('text_box_count')->default(0);
            $table->unsignedInteger('extracted_chars')->default(0);
            $table->decimal('page_coverage_pct', 5, 4)->nullable();

            // Placement metadata from PdfLineNumberService
            $table->string('placement_mode', 50)->nullable();
            $table->unsignedInteger('line_labels_applied')->default(0);

            // Whether this page is billed
            $table->boolean('is_billable')->default(false);

            $table->string('notes')->nullable();

            $table->timestamps();

            $table->index(['processing_report_id', 'page_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_processing_results');
    }
};

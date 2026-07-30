<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pdf_job_id')->constrained('pdf_jobs')->cascadeOnDelete();

            // Page counts
            $table->unsignedInteger('uploaded_pages')->default(0);
            $table->unsignedInteger('successful_pages')->default(0);
            $table->unsignedInteger('low_confidence_pages')->default(0);
            $table->unsignedInteger('failed_pages')->default(0);
            $table->unsignedInteger('payable_pages')->default(0);

            // Pricing snapshot at time of processing
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('currency', 10)->default('KES');

            // Whether low-confidence pages were billed (setting snapshot)
            $table->boolean('bill_low_confidence_pages')->default(false);

            // Quality threshold snapshots (for auditability)
            $table->decimal('threshold_min_confidence', 5, 4)->nullable();
            $table->unsignedInteger('threshold_min_text_boxes')->nullable();
            $table->unsignedInteger('threshold_min_chars')->nullable();
            $table->decimal('threshold_min_page_coverage', 5, 4)->nullable();

            // Optional diagnostics dump (full engine details)
            $table->json('diagnostics')->nullable();

            $table->timestamps();

            $table->index('pdf_job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_reports');
    }
};

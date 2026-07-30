<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_jobs', function (Blueprint $table): void {
            // New status: awaiting_payment (between processing and completed)
            // Status values: pending|processing|awaiting_payment|completed|failed|deleted
            // No column change needed — status is a plain string column.

            // Structured error code for programmatic handling
            $table->string('error_code', 50)->nullable()->after('error_message');

            // Processing report link (denormalized convenience; no FK constraint to avoid circular dep)
            $table->uuid('processing_report_id')->nullable()->after('error_code');

            // How many pages the customer will be charged for
            $table->unsignedInteger('payable_pages')->nullable()->after('processing_report_id');

            // Deadline for payment — after this, the output PDF is purged
            $table->timestamp('payment_deadline_at')->nullable()->after('payable_pages');

            $table->index('payment_deadline_at');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_jobs', function (Blueprint $table): void {
            $table->dropIndex(['payment_deadline_at']);
            $table->dropColumn([
                'error_code',
                'processing_report_id',
                'payable_pages',
                'payment_deadline_at',
            ]);
        });
    }
};

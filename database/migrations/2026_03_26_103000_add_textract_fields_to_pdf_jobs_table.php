<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_jobs', function (Blueprint $table): void {
            $table->string('ocr_provider')->nullable()->after('font_size_pt');
            $table->string('ocr_status')->default('not_started')->after('ocr_provider');
            $table->string('ocr_job_id')->nullable()->after('ocr_status');
            $table->timestamp('ocr_started_at')->nullable()->after('ocr_job_id');
            $table->timestamp('ocr_completed_at')->nullable()->after('ocr_started_at');
            $table->string('ocr_result_path')->nullable()->after('ocr_completed_at');
            $table->text('ocr_error_message')->nullable()->after('ocr_result_path');
            $table->json('ocr_diagnostics')->nullable()->after('ocr_error_message');

            $table->index(['ocr_status']);
            $table->index(['ocr_job_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pdf_jobs', function (Blueprint $table): void {
            $table->dropIndex(['ocr_status']);
            $table->dropIndex(['ocr_job_id']);
            $table->dropColumn([
                'ocr_provider',
                'ocr_status',
                'ocr_job_id',
                'ocr_started_at',
                'ocr_completed_at',
                'ocr_result_path',
                'ocr_error_message',
                'ocr_diagnostics',
            ]);
        });
    }
};

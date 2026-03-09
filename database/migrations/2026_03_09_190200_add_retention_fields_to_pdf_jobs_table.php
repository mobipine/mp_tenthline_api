<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pdf_jobs', function (Blueprint $table): void {
            $table->unsignedInteger('page_count')->default(0)->after('line_interval');
            $table->timestamp('storage_deleted_at')->nullable()->after('output_path');
        });
    }

    public function down(): void
    {
        Schema::table('pdf_jobs', function (Blueprint $table): void {
            $table->dropColumn(['page_count', 'storage_deleted_at']);
        });
    }
};

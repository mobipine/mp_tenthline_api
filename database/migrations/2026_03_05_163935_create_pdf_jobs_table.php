<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pdf_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('filename');
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->unsignedInteger('total_pages')->default(0);
            $table->unsignedInteger('processed_pages')->default(0);
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('eta_seconds')->nullable();
            $table->text('error_message')->nullable();
            $table->string('output_path')->nullable();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->unsignedInteger('line_interval')->default(10);
            $table->string('margin')->default('left'); // left, right
            $table->unsignedTinyInteger('font_size_pt')->default(8);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_jobs');
    }
};

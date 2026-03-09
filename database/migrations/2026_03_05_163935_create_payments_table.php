<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('KES');
            $table->string('phone', 20);
            $table->string('reference', 64)->unique();
            $table->string('mpesa_merchant_request_id')->nullable();
            $table->string('mpesa_checkout_request_id')->nullable();
            $table->string('mpesa_result_code', 20)->nullable();
            $table->json('mpesa_callback_payload')->nullable();
            $table->string('status')->default('pending'); // pending, completed, failed, cancelled
            $table->uuid('pdf_job_id')->nullable(); // set when upload uses this payment
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

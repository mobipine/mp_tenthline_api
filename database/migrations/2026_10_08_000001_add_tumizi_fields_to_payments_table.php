<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('tumizi_payment_id')->nullable()->index();
            $table->string('tumizi_status')->nullable()->index();
            $table->string('tumizi_receipt_number')->nullable()->index();
            $table->string('tumizi_transaction_id')->nullable()->index();
            $table->json('tumizi_callback_payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'tumizi_payment_id', 'tumizi_status', 'tumizi_receipt_number',
                'tumizi_transaction_id', 'tumizi_callback_payload',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('pdf_jobs', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // Backfill payments by matching existing email to users.
        if (Schema::hasColumn('payments', 'email')) {
            $payments = DB::table('payments')
                ->whereNull('user_id')
                ->whereNotNull('email')
                ->select('id', 'email')
                ->get();

            foreach ($payments as $payment) {
                $userId = DB::table('users')->where('email', $payment->email)->value('id');
                if ($userId) {
                    DB::table('payments')->where('id', $payment->id)->update(['user_id' => $userId]);
                }
            }
        }

        // Backfill jobs from linked payments.
        $jobs = DB::table('pdf_jobs')
            ->whereNull('user_id')
            ->whereNotNull('payment_id')
            ->select('id', 'payment_id')
            ->get();

        foreach ($jobs as $job) {
            $userId = DB::table('payments')->where('id', $job->payment_id)->value('user_id');
            if ($userId) {
                DB::table('pdf_jobs')->where('id', $job->id)->update(['user_id' => $userId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('pdf_jobs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};

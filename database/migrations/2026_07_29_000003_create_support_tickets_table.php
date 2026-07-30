<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Human-readable reference e.g. TKT-A1B2C3D4
            $table->string('reference', 20)->unique();

            // Customer details
            $table->string('email');
            $table->string('subject');
            $table->text('description');

            // Status: open | investigating | waiting_for_customer | resolved | closed
            $table->string('status', 30)->default('open');

            // Optional link to registered user
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Optional link to the job this ticket is about
            $table->foreignUuid('pdf_job_id')->nullable()->constrained('pdf_jobs')->nullOnDelete();

            // Internal admin notes (plain text or markdown)
            $table->text('admin_notes')->nullable();

            // Lifecycle timestamps
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('attachments_deleted_at')->nullable();

            $table->timestamps();

            $table->index('email');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->unique()->constrained('payroll_runs_v2')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->string('posting_status', 20)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('posting_status', 'payroll_postings_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_postings');
    }
};

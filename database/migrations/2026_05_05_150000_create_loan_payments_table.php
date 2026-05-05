<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->unsignedInteger('installment_no');
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'installment_no']);
            $table->index('payroll_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_payments');
    }
};

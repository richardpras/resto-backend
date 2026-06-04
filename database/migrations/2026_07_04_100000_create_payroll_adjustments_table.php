<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('adjustment_no', 50);
            $table->string('type', 20);
            $table->string('category', 30);
            $table->decimal('amount', 15, 2);
            $table->date('effective_from');
            $table->date('effective_to');
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'adjustment_no'], 'payroll_adj_outlet_no_uniq');
            $table->index(['employee_id', 'status', 'effective_from', 'effective_to'], 'payroll_adj_emp_period_idx');
        });

        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->decimal('adjustment_earning', 15, 2)->default(0)->after('remaining_cash_advance_balance');
            $table->decimal('adjustment_deduction', 15, 2)->default(0)->after('adjustment_earning');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->dropColumn(['adjustment_earning', 'adjustment_deduction']);
        });

        Schema::dropIfExists('payroll_adjustments');
    }
};

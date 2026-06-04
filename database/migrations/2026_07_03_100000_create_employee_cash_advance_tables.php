<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_cash_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('advance_no', 50);
            $table->decimal('amount', 15, 2);
            $table->string('repayment_type', 20);
            $table->unsignedSmallInteger('installment_count')->nullable();
            $table->decimal('installment_amount', 15, 2)->nullable();
            $table->decimal('deducted_amount', 15, 2)->default(0);
            $table->decimal('remaining_amount', 15, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'advance_no'], 'emp_cash_adv_outlet_no_uniq');
            $table->index(['employee_id', 'status'], 'emp_cash_adv_emp_status_idx');
        });

        Schema::create('employee_cash_advance_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_advance_id')->constrained('employee_cash_advances')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_no');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('unpaid');
            $table->foreignId('payroll_run_item_id')->nullable()->constrained('payroll_run_items_v2')->nullOnDelete();
            $table->timestamps();

            $table->unique(['cash_advance_id', 'installment_no'], 'emp_cash_adv_inst_no_uniq');
            $table->index(['cash_advance_id', 'status', 'due_date'], 'emp_cash_adv_inst_due_idx');
        });

        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->decimal('cash_advance_deduction', 15, 2)->default(0)->after('remaining_loan_balance');
            $table->decimal('remaining_cash_advance_balance', 15, 2)->default(0)->after('cash_advance_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->dropColumn(['cash_advance_deduction', 'remaining_cash_advance_balance']);
        });

        Schema::dropIfExists('employee_cash_advance_installments');
        Schema::dropIfExists('employee_cash_advances');
    }
};

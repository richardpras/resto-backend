<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('loan_no', 50);
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('installment_amount', 15, 2);
            $table->unsignedSmallInteger('total_installments');
            $table->unsignedSmallInteger('paid_installments')->default(0);
            $table->decimal('remaining_balance', 15, 2)->default(0);
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'loan_no'], 'emp_loans_outlet_no_uniq');
            $table->index(['employee_id', 'status'], 'emp_loans_emp_status_idx');
        });

        Schema::create('employee_loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('employee_loans')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_no');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('unpaid');
            $table->foreignId('payroll_run_item_id')->nullable()->constrained('payroll_run_items_v2')->nullOnDelete();
            $table->timestamps();

            $table->unique(['loan_id', 'installment_no'], 'emp_loan_inst_loan_no_uniq');
            $table->index(['loan_id', 'status', 'due_date'], 'emp_loan_inst_due_idx');
        });

        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->decimal('loan_deduction', 15, 2)->default(0)->after('attendance_deduction');
            $table->decimal('remaining_loan_balance', 15, 2)->default(0)->after('loan_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->dropColumn(['loan_deduction', 'remaining_loan_balance']);
        });

        Schema::dropIfExists('employee_loan_installments');
        Schema::dropIfExists('employee_loans');
    }
};

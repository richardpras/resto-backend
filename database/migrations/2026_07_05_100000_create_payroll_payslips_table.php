<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs_v2')->cascadeOnDelete();
            $table->foreignId('payroll_run_item_id')->constrained('payroll_run_items_v2')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('payroll_preparation_periods')->cascadeOnDelete();
            $table->string('payslip_no', 50);
            $table->decimal('gross_salary', 15, 2);
            $table->decimal('total_deductions', 15, 2);
            $table->decimal('net_salary', 15, 2);
            $table->json('breakdown_json');
            $table->string('pdf_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique('payroll_run_item_id', 'payroll_payslips_run_item_uniq');
            $table->unique(['outlet_id', 'payslip_no'], 'payroll_payslips_outlet_no_uniq');
            $table->index(['employee_id', 'status'], 'payroll_payslips_emp_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payslips');
    }
};

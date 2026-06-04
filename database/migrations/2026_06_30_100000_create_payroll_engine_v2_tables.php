<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_salary_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('type', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'pay_sal_comp_outlet_code_uniq');
        });

        Schema::create('employee_salary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->decimal('default_allowance', 15, 2)->default(0);
            $table->decimal('default_deduction', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('payroll_runs_v2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('payroll_preparation_period_id')->unique()->constrained('payroll_preparation_periods')->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payroll_run_items_v2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs_v2')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->unsignedSmallInteger('attendance_days')->default(0);
            $table->decimal('leave_days', 8, 2)->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->decimal('gross_salary', 15, 2)->default(0);
            $table->decimal('total_deductions', 15, 2)->default(0);
            $table->decimal('net_salary', 15, 2)->default(0);
            $table->json('calculation_json')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id'], 'pay_run_items_v2_run_emp_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_items_v2');
        Schema::dropIfExists('payroll_runs_v2');
        Schema::dropIfExists('employee_salary_profiles');
        Schema::dropIfExists('payroll_salary_components');
    }
};

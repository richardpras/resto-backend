<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_preparation_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'period_start', 'period_end'], 'pay_prep_period_outlet_range_uniq');
        });

        Schema::create('payroll_preparation_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preparation_period_id')->constrained('payroll_preparation_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->unsignedSmallInteger('scheduled_days')->default(0);
            $table->unsignedSmallInteger('attended_days')->default(0);
            $table->unsignedSmallInteger('absent_days')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->decimal('leave_days', 8, 2)->default(0);
            $table->decimal('paid_leave_days', 8, 2)->default(0);
            $table->decimal('unpaid_leave_days', 8, 2)->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->decimal('overtime_hours', 8, 2)->default(0);
            $table->boolean('review_required')->default(false);
            $table->json('snapshot_json')->nullable();
            $table->timestamps();

            $table->unique(['preparation_period_id', 'employee_id'], 'pay_prep_snap_period_emp_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_preparation_snapshots');
        Schema::dropIfExists('payroll_preparation_periods');
    }
};

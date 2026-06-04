<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_salary_profiles', function (Blueprint $table) {
            $table->string('overtime_rate_type', 40)->default('fixed_hourly')->after('default_deduction');
            $table->decimal('overtime_rate_value', 12, 4)->default(0)->after('overtime_rate_type');
            $table->boolean('unpaid_leave_deduction_enabled')->default(true)->after('overtime_rate_value');
            $table->boolean('attendance_deduction_enabled')->default(false)->after('unpaid_leave_deduction_enabled');
            $table->decimal('attendance_deduction_per_day', 15, 2)->nullable()->after('attendance_deduction_enabled');
        });

        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->decimal('overtime_pay', 15, 2)->default(0)->after('overtime_hours');
            $table->decimal('unpaid_leave_days', 8, 2)->default(0)->after('leave_days');
            $table->decimal('unpaid_leave_deduction', 15, 2)->default(0)->after('unpaid_leave_days');
            $table->unsignedSmallInteger('absent_days')->default(0)->after('attendance_days');
            $table->decimal('attendance_deduction', 15, 2)->default(0)->after('absent_days');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->dropColumn([
                'overtime_pay',
                'unpaid_leave_days',
                'unpaid_leave_deduction',
                'absent_days',
                'attendance_deduction',
            ]);
        });

        Schema::table('employee_salary_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'overtime_rate_type',
                'overtime_rate_value',
                'unpaid_leave_deduction_enabled',
                'attendance_deduction_enabled',
                'attendance_deduction_per_day',
            ]);
        });
    }
};

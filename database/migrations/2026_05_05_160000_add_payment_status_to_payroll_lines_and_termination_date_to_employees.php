<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table): void {
            $table->enum('payment_status', ['unlocked', 'locked'])->default('unlocked')->after('overtime_hours');
            $table->index('payment_status');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->date('termination_date')->nullable()->after('hire_date');
            $table->index('termination_date');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_lines', function (Blueprint $table): void {
            $table->dropIndex(['payment_status']);
            $table->dropColumn('payment_status');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex(['termination_date']);
            $table->dropColumn('termination_date');
        });
    }
};

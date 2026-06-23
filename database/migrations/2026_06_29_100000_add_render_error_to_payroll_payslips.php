<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_payslips') || Schema::hasColumn('payroll_payslips', 'render_error')) {
            return;
        }

        Schema::table('payroll_payslips', function (Blueprint $table): void {
            $table->text('render_error')->nullable()->after('pdf_path');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payroll_payslips') || ! Schema::hasColumn('payroll_payslips', 'render_error')) {
            return;
        }

        Schema::table('payroll_payslips', function (Blueprint $table): void {
            $table->dropColumn('render_error');
        });
    }
};

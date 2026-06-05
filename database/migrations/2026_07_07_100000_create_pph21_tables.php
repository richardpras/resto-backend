<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pph21_configs', function (Blueprint $table) {
            $table->id();
            $table->date('effective_date');
            $table->decimal('ptkp_tk0', 15, 2)->default(54000000);
            $table->decimal('ptkp_tk1', 15, 2)->default(58500000);
            $table->decimal('ptkp_tk2', 15, 2)->default(63000000);
            $table->decimal('ptkp_tk3', 15, 2)->default(67500000);
            $table->decimal('ptkp_k0', 15, 2)->default(58500000);
            $table->decimal('ptkp_k1', 15, 2)->default(63000000);
            $table->decimal('ptkp_k2', 15, 2)->default(67500000);
            $table->decimal('ptkp_k3', 15, 2)->default(72000000);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'effective_date'], 'pph21_configs_active_date_idx');
        });

        Schema::create('pph21_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pph21_config_id')->constrained('pph21_configs')->cascadeOnDelete();
            $table->decimal('income_from', 15, 2)->default(0);
            $table->decimal('income_to', 15, 2)->nullable();
            $table->decimal('tax_rate', 8, 4);
            $table->timestamps();

            $table->index(['pph21_config_id', 'income_from'], 'pph21_brackets_config_from_idx');
        });

        Schema::create('employee_tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('npwp_number', 50)->nullable();
            $table->string('ptkp_status', 10)->default('TK0');
            $table->boolean('pph21_enabled')->default(false);
            $table->timestamps();
        });

        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->decimal('taxable_income', 15, 2)->default(0)->after('bpjs_jkm_company');
            $table->decimal('annual_taxable_income', 15, 2)->default(0)->after('taxable_income');
            $table->decimal('pph21_amount', 15, 2)->default(0)->after('annual_taxable_income');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->dropColumn(['taxable_income', 'annual_taxable_income', 'pph21_amount']);
        });

        Schema::dropIfExists('employee_tax_profiles');
        Schema::dropIfExists('pph21_brackets');
        Schema::dropIfExists('pph21_configs');
    }
};

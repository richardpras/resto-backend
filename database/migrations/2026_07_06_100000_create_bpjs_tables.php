<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bpjs_configs', function (Blueprint $table) {
            $table->id();
            $table->date('effective_date');
            $table->decimal('kesehatan_employee_rate', 8, 4)->default(1);
            $table->decimal('kesehatan_company_rate', 8, 4)->default(4);
            $table->decimal('jht_employee_rate', 8, 4)->default(2);
            $table->decimal('jht_company_rate', 8, 4)->default(3.7);
            $table->decimal('jp_employee_rate', 8, 4)->default(1);
            $table->decimal('jp_company_rate', 8, 4)->default(2);
            $table->decimal('jkk_company_rate', 8, 4)->default(0.24);
            $table->decimal('jkm_company_rate', 8, 4)->default(0.3);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['status', 'effective_date'], 'bpjs_configs_active_date_idx');
        });

        Schema::create('bpjs_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('bpjs_kesehatan_no', 50)->nullable();
            $table->string('bpjs_tk_no', 50)->nullable();
            $table->boolean('bpjs_kesehatan_enabled')->default(false);
            $table->boolean('bpjs_tk_enabled')->default(false);
            $table->decimal('bpjs_salary_base', 15, 2)->nullable();
            $table->decimal('kesehatan_employee_rate_override', 8, 4)->nullable();
            $table->decimal('kesehatan_company_rate_override', 8, 4)->nullable();
            $table->decimal('jht_employee_rate_override', 8, 4)->nullable();
            $table->decimal('jht_company_rate_override', 8, 4)->nullable();
            $table->decimal('jp_employee_rate_override', 8, 4)->nullable();
            $table->decimal('jp_company_rate_override', 8, 4)->nullable();
            $table->decimal('jkk_company_rate_override', 8, 4)->nullable();
            $table->decimal('jkm_company_rate_override', 8, 4)->nullable();
            $table->timestamps();
        });

        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->decimal('bpjs_kesehatan_employee', 15, 2)->default(0)->after('adjustment_deduction');
            $table->decimal('bpjs_kesehatan_company', 15, 2)->default(0)->after('bpjs_kesehatan_employee');
            $table->decimal('bpjs_jht_employee', 15, 2)->default(0)->after('bpjs_kesehatan_company');
            $table->decimal('bpjs_jht_company', 15, 2)->default(0)->after('bpjs_jht_employee');
            $table->decimal('bpjs_jp_employee', 15, 2)->default(0)->after('bpjs_jht_company');
            $table->decimal('bpjs_jp_company', 15, 2)->default(0)->after('bpjs_jp_employee');
            $table->decimal('bpjs_jkk_company', 15, 2)->default(0)->after('bpjs_jp_company');
            $table->decimal('bpjs_jkm_company', 15, 2)->default(0)->after('bpjs_jkk_company');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_run_items_v2', function (Blueprint $table) {
            $table->dropColumn([
                'bpjs_kesehatan_employee',
                'bpjs_kesehatan_company',
                'bpjs_jht_employee',
                'bpjs_jht_company',
                'bpjs_jp_employee',
                'bpjs_jp_company',
                'bpjs_jkk_company',
                'bpjs_jkm_company',
            ]);
        });

        Schema::dropIfExists('bpjs_profiles');
        Schema::dropIfExists('bpjs_configs');
    }
};

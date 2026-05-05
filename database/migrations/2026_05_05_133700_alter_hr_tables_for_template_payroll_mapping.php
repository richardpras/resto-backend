<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'outlet')) {
                $table->string('outlet')->nullable()->after('position');
            }
            if (! Schema::hasColumn('employees', 'salary_type')) {
                $table->enum('salary_type', ['monthly', 'daily', 'hourly'])->default('monthly')->after('outlet');
            }
            if (! Schema::hasColumn('employees', 'overtime_rate')) {
                $table->decimal('overtime_rate', 14, 2)->default(0)->after('base_salary');
            }
            if (! Schema::hasColumn('employees', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('employees', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('shifts', 'notes')) {
                $table->text('notes')->nullable()->after('active');
            }
            if (! Schema::hasColumn('shifts', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('shifts', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('attendances', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('attendances', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }
            if (Schema::hasColumn('attendances', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
        });

        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }
            if (Schema::hasColumn('shifts', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            if (Schema::hasColumn('shifts', 'notes')) {
                $table->dropColumn('notes');
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'updated_by')) {
                $table->dropConstrainedForeignId('updated_by');
            }
            if (Schema::hasColumn('employees', 'created_by')) {
                $table->dropConstrainedForeignId('created_by');
            }
            if (Schema::hasColumn('employees', 'overtime_rate')) {
                $table->dropColumn('overtime_rate');
            }
            if (Schema::hasColumn('employees', 'salary_type')) {
                $table->dropColumn('salary_type');
            }
            if (Schema::hasColumn('employees', 'outlet')) {
                $table->dropColumn('outlet');
            }
        });
    }
};

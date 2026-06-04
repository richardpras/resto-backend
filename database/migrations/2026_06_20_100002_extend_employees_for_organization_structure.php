<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('employees', 'outlet_id')) {
                $table->foreignId('outlet_id')->nullable()->after('user_id')->constrained('outlets')->nullOnDelete();
            }
            if (! Schema::hasColumn('employees', 'gender')) {
                $table->string('gender', 32)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('employees', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('gender');
            }
            if (! Schema::hasColumn('employees', 'position_id')) {
                $table->foreignId('position_id')->nullable()->after('position')->constrained('positions')->nullOnDelete();
            }
            if (! Schema::hasColumn('employees', 'department_id')) {
                $table->foreignId('department_id')->nullable()->after('position_id')->constrained('departments')->nullOnDelete();
            }
            if (! Schema::hasColumn('employees', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
        });

        $defaultOutletId = DB::table('outlets')->orderBy('id')->value('id');
        if ($defaultOutletId !== null) {
            DB::table('employees')
                ->whereNull('outlet_id')
                ->update(['outlet_id' => $defaultOutletId]);
        }

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['employee_no']);
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->unique(['outlet_id', 'employee_no'], 'employees_outlet_employee_no_unique');
        });

        DB::statement("ALTER TABLE employees MODIFY status VARCHAR(32) NOT NULL DEFAULT 'active'");
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_outlet_employee_no_unique');
            $table->unique('employee_no');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('position_id');
            $table->dropConstrainedForeignId('outlet_id');
            $table->dropColumn(['gender', 'birth_date', 'notes']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attendance_period_locks', 'payroll_preparation_period_id')) {
            Schema::table('attendance_period_locks', function (Blueprint $table) {
                $table->foreignId('payroll_preparation_period_id')
                    ->nullable()
                    ->after('outlet_id')
                    ->constrained('payroll_preparation_periods')
                    ->cascadeOnDelete();

                $table->unique('payroll_preparation_period_id', 'att_period_locks_pay_prep_uniq');
            });

            return;
        }

        if (! $this->foreignKeyExists('attendance_period_locks', 'attendance_period_locks_payroll_preparation_period_id_foreign')) {
            Schema::table('attendance_period_locks', function (Blueprint $table) {
                $table->foreign('payroll_preparation_period_id', 'attendance_period_locks_payroll_preparation_period_id_foreign')
                    ->references('id')
                    ->on('payroll_preparation_periods')
                    ->cascadeOnDelete();
            });
        }

        if (! $this->indexExists('attendance_period_locks', 'att_period_locks_pay_prep_uniq')) {
            Schema::table('attendance_period_locks', function (Blueprint $table) {
                $table->unique('payroll_preparation_period_id', 'att_period_locks_pay_prep_uniq');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('attendance_period_locks', 'payroll_preparation_period_id')) {
            return;
        }

        Schema::table('attendance_period_locks', function (Blueprint $table) {
            if ($this->foreignKeyExists('attendance_period_locks', 'attendance_period_locks_payroll_preparation_period_id_foreign')) {
                $table->dropForeign('attendance_period_locks_payroll_preparation_period_id_foreign');
            }

            if ($this->indexExists('attendance_period_locks', 'att_period_locks_pay_prep_uniq')) {
                $table->dropUnique('att_period_locks_pay_prep_uniq');
            }

            $table->dropColumn('payroll_preparation_period_id');
        });
    }

    private function foreignKeyExists(string $table, string $foreignKey): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$database, $table, $foreignKey, 'FOREIGN KEY'],
        );

        return $row !== null;
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?
             LIMIT 1',
            [$database, $table, $index],
        );

        return $row !== null;
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            if (! Schema::hasColumn('outlets', 'default_cash_float')) {
                $table->decimal('default_cash_float', 14, 2)->default(500000)->after('order_prefix');
            }
        });

        Schema::table('pos_sessions', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_sessions', 'expected_cash')) {
                $table->decimal('expected_cash', 14, 2)->nullable()->after('cash_variance');
            }
            if (! Schema::hasColumn('pos_sessions', 'actual_cash')) {
                $table->decimal('actual_cash', 14, 2)->nullable()->after('expected_cash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_sessions', function (Blueprint $table): void {
            if (Schema::hasColumn('pos_sessions', 'actual_cash')) {
                $table->dropColumn('actual_cash');
            }
            if (Schema::hasColumn('pos_sessions', 'expected_cash')) {
                $table->dropColumn('expected_cash');
            }
        });

        Schema::table('outlets', function (Blueprint $table): void {
            if (Schema::hasColumn('outlets', 'default_cash_float')) {
                $table->dropColumn('default_cash_float');
            }
        });
    }
};

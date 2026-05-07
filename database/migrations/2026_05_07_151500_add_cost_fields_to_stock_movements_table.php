<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'unit_cost')) {
                $table->decimal('unit_cost', 14, 4)->nullable()->after('source_id');
            }
            if (! Schema::hasColumn('stock_movements', 'total_cost')) {
                $table->decimal('total_cost', 14, 4)->nullable()->after('unit_cost');
            }
            if (! Schema::hasColumn('stock_movements', 'ledger_payload')) {
                $table->json('ledger_payload')->nullable()->after('total_cost');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_movements', 'ledger_payload')) {
                $table->dropColumn('ledger_payload');
            }
            if (Schema::hasColumn('stock_movements', 'total_cost')) {
                $table->dropColumn('total_cost');
            }
            if (Schema::hasColumn('stock_movements', 'unit_cost')) {
                $table->dropColumn('unit_cost');
            }
        });
    }
};

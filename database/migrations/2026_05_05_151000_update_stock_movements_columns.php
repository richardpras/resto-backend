<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'inventory_item_id')) {
                $table->unsignedBigInteger('inventory_item_id')->nullable()->after('id');
                $table->index('inventory_item_id');
            }

            if (! Schema::hasColumn('stock_movements', 'type')) {
                $table->string('type', 20)->nullable()->after('inventory_item_id');
            }

            if (! Schema::hasColumn('stock_movements', 'source_type')) {
                $table->string('source_type')->nullable()->after('quantity');
            }

            if (! Schema::hasColumn('stock_movements', 'source_id')) {
                $table->string('source_id')->nullable()->after('source_type');
            }
        });

        if (Schema::hasColumn('stock_movements', 'ingredient_id')) {
            DB::statement('UPDATE stock_movements SET inventory_item_id = ingredient_id WHERE inventory_item_id IS NULL');
        }

        if (Schema::hasColumn('stock_movements', 'movement_type')) {
            DB::statement("
                UPDATE stock_movements
                SET type = CASE movement_type
                    WHEN 'in' THEN 'purchase'
                    WHEN 'out' THEN 'sale'
                    ELSE 'adjustment'
                END
                WHERE type IS NULL
            ");
        }

        if (Schema::hasColumn('stock_movements', 'source')) {
            DB::statement('UPDATE stock_movements SET source_type = source WHERE source_type IS NULL');
        }

        if (Schema::hasColumn('stock_movements', 'reference_no')) {
            DB::statement('UPDATE stock_movements SET source_id = reference_no WHERE source_id IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_movements', 'source_id')) {
                $table->dropColumn('source_id');
            }

            if (Schema::hasColumn('stock_movements', 'source_type')) {
                $table->dropColumn('source_type');
            }

            if (Schema::hasColumn('stock_movements', 'type')) {
                $table->dropColumn('type');
            }

            if (Schema::hasColumn('stock_movements', 'inventory_item_id')) {
                $table->dropIndex(['inventory_item_id']);
                $table->dropColumn('inventory_item_id');
            }
        });
    }
};

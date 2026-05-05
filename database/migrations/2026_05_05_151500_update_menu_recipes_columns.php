<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_recipes', function (Blueprint $table): void {
            if (! Schema::hasColumn('menu_recipes', 'inventory_item_id')) {
                $table->unsignedBigInteger('inventory_item_id')->nullable()->after('menu_item_id');
                $table->index('inventory_item_id');
            }

            if (! Schema::hasColumn('menu_recipes', 'quantity')) {
                $table->decimal('quantity', 14, 2)->nullable()->after('inventory_item_id');
            }
        });

        if (Schema::hasColumn('menu_recipes', 'ingredient_id')) {
            DB::statement('UPDATE menu_recipes SET inventory_item_id = ingredient_id WHERE inventory_item_id IS NULL');
        }

        if (Schema::hasColumn('menu_recipes', 'qty')) {
            DB::statement('UPDATE menu_recipes SET quantity = qty WHERE quantity IS NULL');
        }
    }

    public function down(): void
    {
        Schema::table('menu_recipes', function (Blueprint $table): void {
            if (Schema::hasColumn('menu_recipes', 'quantity')) {
                $table->dropColumn('quantity');
            }

            if (Schema::hasColumn('menu_recipes', 'inventory_item_id')) {
                $table->dropIndex(['inventory_item_id']);
                $table->dropColumn('inventory_item_id');
            }
        });
    }
};

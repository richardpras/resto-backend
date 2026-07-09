<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->string('inventory_costing_method', 32)
                ->default('moving_average')
                ->after('allow_negative_stock');
        });

        if (Schema::hasTable('order_item_cost_snapshots') && ! Schema::hasColumn('order_item_cost_snapshots', 'costing_method_snapshot')) {
            Schema::table('order_item_cost_snapshots', function (Blueprint $table): void {
                $table->string('costing_method_snapshot', 32)->nullable()->after('average_cost_version');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_item_cost_snapshots') && Schema::hasColumn('order_item_cost_snapshots', 'costing_method_snapshot')) {
            Schema::table('order_item_cost_snapshots', function (Blueprint $table): void {
                $table->dropColumn('costing_method_snapshot');
            });
        }

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('inventory_costing_method');
        });
    }
};

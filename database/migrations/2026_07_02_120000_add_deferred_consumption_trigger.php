<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->string('deferred_consumption_trigger', 32)
                ->default('shift_close')
                ->after('inventory_costing_method');
        });

        Schema::table('outlet_inventory_settings', function (Blueprint $table): void {
            $table->string('deferred_consumption_trigger', 32)->nullable()->after('allow_negative_stock');
        });
    }

    public function down(): void
    {
        Schema::table('outlet_inventory_settings', function (Blueprint $table): void {
            $table->dropColumn('deferred_consumption_trigger');
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('deferred_consumption_trigger');
        });
    }
};

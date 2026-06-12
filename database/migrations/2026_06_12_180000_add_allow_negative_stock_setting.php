<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->boolean('allow_negative_stock')->default(true)->after('stock_enforcement_mode');
        });

        Schema::table('outlet_inventory_settings', function (Blueprint $table): void {
            $table->boolean('allow_negative_stock')->nullable()->after('stock_enforcement_mode');
        });
    }

    public function down(): void
    {
        Schema::table('outlet_inventory_settings', function (Blueprint $table): void {
            $table->dropColumn('allow_negative_stock');
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('allow_negative_stock');
        });
    }
};

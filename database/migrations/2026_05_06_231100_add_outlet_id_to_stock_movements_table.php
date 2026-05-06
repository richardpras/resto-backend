<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_movements', 'outlet_id')) {
                $table->unsignedBigInteger('outlet_id')->nullable()->after('inventory_item_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_movements', 'outlet_id')) {
                $table->dropColumn('outlet_id');
            }
        });
    }
};

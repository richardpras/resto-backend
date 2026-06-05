<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_orders', 'destination_warehouse_id')) {
                $table->foreignId('destination_warehouse_id')->nullable()->after('supplier_id')->constrained('warehouses')->nullOnDelete();
            }
        });

        Schema::table('goods_receiving_notes', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_receiving_notes', 'destination_warehouse_id')) {
                $table->foreignId('destination_warehouse_id')->nullable()->after('purchase_order_id')->constrained('warehouses')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('goods_receiving_notes', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_receiving_notes', 'destination_warehouse_id')) {
                $table->dropConstrainedForeignId('destination_warehouse_id');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_orders', 'destination_warehouse_id')) {
                $table->dropConstrainedForeignId('destination_warehouse_id');
            }
        });
    }
};

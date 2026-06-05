<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receiving_notes', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_receiving_notes', 'status')) {
                $table->enum('status', ['draft', 'received', 'posted', 'cancelled'])->default('draft')->after('notes');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'warehouse_id')) {
                $table->unsignedBigInteger('warehouse_id')->nullable()->after('destination_warehouse_id');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'supplier_delivery_no')) {
                $table->string('supplier_delivery_no')->nullable()->after('warehouse_id');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'supplier_delivery_date')) {
                $table->date('supplier_delivery_date')->nullable()->after('supplier_delivery_no');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'vehicle_no')) {
                $table->string('vehicle_no')->nullable()->after('supplier_delivery_date');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'driver_name')) {
                $table->string('driver_name')->nullable()->after('vehicle_no');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'received_by')) {
                $table->string('received_by')->nullable()->after('driver_name');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('received_by');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->after('received_at');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('posted_at');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'posted_by')) {
                $table->unsignedBigInteger('posted_by')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('goods_receiving_notes', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('posted_by');
            }
        });

        DB::statement('UPDATE goods_receiving_notes SET warehouse_id = COALESCE(warehouse_id, destination_warehouse_id)');
        DB::statement('UPDATE goods_receiving_notes SET status = "posted", posted_at = COALESCE(posted_at, created_at) WHERE id > 0');
    }

    public function down(): void
    {
        Schema::table('goods_receiving_notes', function (Blueprint $table): void {
            $columns = [
                'cancelled_by', 'posted_by', 'cancelled_at', 'posted_at', 'received_at',
                'received_by', 'driver_name', 'vehicle_no', 'supplier_delivery_date',
                'supplier_delivery_no', 'warehouse_id', 'status',
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('goods_receiving_notes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

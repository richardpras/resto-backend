<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_receiving_note_items', 'original_po_cost')) {
                $table->decimal('original_po_cost', 14, 4)->default(0)->after('received_qty');
            }
            if (! Schema::hasColumn('goods_receiving_note_items', 'actual_received_cost')) {
                $table->decimal('actual_received_cost', 14, 4)->default(0)->after('original_po_cost');
            }
        });

        if (Schema::hasColumn('purchase_orders', 'purchase_request_id')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->dropForeign(['purchase_request_id']);
            });
            DB::statement('ALTER TABLE purchase_orders MODIFY purchase_request_id BIGINT UNSIGNED NULL');
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('purchase_invoice_items')) {
            Schema::create('purchase_invoice_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->cascadeOnDelete();
                $table->foreignId('goods_receiving_note_item_id')->nullable()->constrained('goods_receiving_note_items')->nullOnDelete();
                $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
                $table->decimal('qty', 14, 2);
                $table->decimal('unit_price', 14, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');

        Schema::table('goods_receiving_note_items', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_receiving_note_items', 'actual_received_cost')) {
                $table->dropColumn('actual_received_cost');
            }
            if (Schema::hasColumn('goods_receiving_note_items', 'original_po_cost')) {
                $table->dropColumn('original_po_cost');
            }
        });
    }
};

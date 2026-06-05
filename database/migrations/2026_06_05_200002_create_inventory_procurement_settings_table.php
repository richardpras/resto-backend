<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_procurement_settings')) {
            return;
        }

        Schema::create('inventory_procurement_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained('ingredients')->cascadeOnDelete();
            $table->foreignId('preferred_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->decimal('minimum_order_qty', 14, 2)->nullable();
            $table->decimal('reorder_qty', 14, 2)->nullable();
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->decimal('last_purchase_price', 14, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['inventory_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_procurement_settings');
    }
};

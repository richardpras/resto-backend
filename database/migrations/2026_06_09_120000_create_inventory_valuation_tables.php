<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_valuations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->unsignedBigInteger('outlet_id');
            $table->decimal('stock_quantity', 14, 4)->default(0);
            $table->decimal('inventory_value', 14, 4)->default(0);
            $table->decimal('average_cost', 14, 4)->default(0);
            $table->decimal('last_purchase_cost', 14, 4)->default(0);
            $table->unsignedBigInteger('last_grn_id')->nullable();
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['ingredient_id', 'outlet_id']);
            $table->index('outlet_id');
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });

        Schema::create('order_item_cost_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->unsignedBigInteger('menu_item_id')->nullable();
            $table->unsignedBigInteger('outlet_id');
            $table->decimal('cost_per_unit', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 4)->default(0);
            $table->string('average_cost_version', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('order_item_id');
            $table->index(['outlet_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_cost_snapshots');
        Schema::dropIfExists('inventory_valuations');
    }
};

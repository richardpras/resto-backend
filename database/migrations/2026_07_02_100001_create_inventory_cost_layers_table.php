<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_cost_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('source_movement_id')->nullable();
            $table->unsignedBigInteger('grn_id')->nullable();
            $table->decimal('qty_received', 14, 4)->default(0);
            $table->decimal('qty_remaining', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->index(['ingredient_id', 'outlet_id', 'received_at']);
            $table->unique(['ingredient_id', 'outlet_id', 'source_movement_id'], 'inventory_cost_layers_movement_unique');
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('source_movement_id')->references('id')->on('stock_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_cost_layers');
    }
};

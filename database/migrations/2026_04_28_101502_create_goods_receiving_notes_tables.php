<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('goods_receiving_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->string('number')->unique();
            $table->date('received_date');
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('goods_receiving_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receiving_note_id')->constrained('goods_receiving_notes')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('received_qty', 14, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goods_receiving_note_items');
        Schema::dropIfExists('goods_receiving_notes');
    }
};

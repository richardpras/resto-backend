<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_split_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_split_id')->constrained('order_splits')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->decimal('qty', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->unique(['order_split_id', 'order_item_id']);
            $table->index(['order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_split_items');
    }
};

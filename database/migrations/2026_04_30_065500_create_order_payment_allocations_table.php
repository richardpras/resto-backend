<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->decimal('qty', 14, 2);
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->index(['payment_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_allocations');
    }
};

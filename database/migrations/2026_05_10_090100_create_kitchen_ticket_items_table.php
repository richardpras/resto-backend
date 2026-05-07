<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_ticket_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kitchen_ticket_id')->constrained('kitchen_tickets')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->string('item_name_snapshot');
            $table->decimal('qty', 14, 2);
            $table->string('notes')->nullable();
            $table->string('status', 32)->default('queued');
            $table->timestamps();

            $table->index(['kitchen_ticket_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_items');
    }
};

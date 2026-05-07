<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('ticket_no', 64)->unique();
            $table->string('status', 32)->default('queued');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');
    }
};

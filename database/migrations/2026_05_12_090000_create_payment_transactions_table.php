<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_split_id')->nullable()->constrained('order_splits')->nullOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('external_reference', 120);
            $table->string('idempotency_key', 120);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3)->default('IDR');
            $table->enum('status', ['pending', 'authorized', 'paid', 'failed', 'expired', 'cancelled', 'refunded'])->default('pending');
            $table->string('payment_method', 64)->nullable();
            $table->json('payload_snapshot')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'idempotency_key'], 'payment_tx_provider_idempotency_unique');
            $table->unique(['provider', 'external_reference'], 'payment_tx_provider_external_unique');
            $table->index(['order_id', 'status'], 'payment_tx_order_status_index');
            $table->index(['order_split_id', 'status'], 'payment_tx_split_status_index');
            $table->index(['outlet_id', 'status'], 'payment_tx_outlet_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};

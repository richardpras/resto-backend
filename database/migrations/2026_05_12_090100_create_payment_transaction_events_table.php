<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transaction_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('event_idempotency_key', 120)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_transaction_id', 'created_at'], 'payment_tx_events_timeline_index');
            $table->index(['event_type'], 'payment_tx_events_type_index');
            $table->unique(['payment_transaction_id', 'event_idempotency_key'], 'payment_tx_events_dedup_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transaction_events');
    }
};

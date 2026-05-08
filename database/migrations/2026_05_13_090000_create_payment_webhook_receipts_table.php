<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 64);
            $table->string('event_idempotency_key', 191);
            $table->string('external_reference', 120);
            $table->string('incoming_status', 32);
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->unsignedSmallInteger('process_attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_idempotency_key'], 'payment_webhook_receipt_dedup_unique');
            $table->index(['processed_at', 'next_retry_at'], 'payment_webhook_retry_index');
            $table->index(['provider', 'external_reference'], 'payment_webhook_reference_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_receipts');
    }
};

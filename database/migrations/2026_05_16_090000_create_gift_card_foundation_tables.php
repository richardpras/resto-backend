<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_card_issuances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('instrument_type', 32)->default('gift_card');
            $table->string('code', 120);
            $table->decimal('issued_amount', 18, 2);
            $table->decimal('balance_amount', 18, 2);
            $table->string('currency', 3)->default('IDR');
            $table->string('status', 32)->default('active');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_redeemed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'gift_card_issuances_outlet_code_unique');
            $table->index(['outlet_id', 'instrument_type', 'status'], 'gift_card_issuances_scope_status_index');
            $table->index(['expires_at', 'status'], 'gift_card_issuances_expiry_index');
        });

        Schema::create('gift_card_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issuance_id')->constrained('gift_card_issuances')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('transaction_type', 40);
            $table->string('idempotency_key', 120);
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->decimal('amount_delta', 18, 2);
            $table->decimal('balance_before', 18, 2);
            $table->decimal('balance_after', 18, 2);
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'idempotency_key'], 'gift_card_ledgers_outlet_idempotency_unique');
            $table->index(['issuance_id', 'created_at'], 'gift_card_ledgers_issuance_created_index');
        });

        Schema::create('gift_card_redemption_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issuance_id')->constrained('gift_card_issuances')->cascadeOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('gift_card_ledgers')->nullOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('idempotency_key', 120);
            $table->string('settlement_reference', 120)->nullable();
            $table->unsignedBigInteger('payment_transaction_id')->nullable();
            $table->decimal('redeemed_amount', 18, 2);
            $table->string('status', 32)->default('pending');
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'idempotency_key'], 'gift_card_settlements_outlet_idempotency_unique');
            $table->index(['outlet_id', 'status', 'settled_at'], 'gift_card_settlements_status_index');
        });

        Schema::create('gift_card_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('issuance_id')->nullable()->constrained('gift_card_issuances')->nullOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('event_idempotency_key', 160)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'event_type', 'created_at'], 'gift_card_events_scope_type_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_events');
        Schema::dropIfExists('gift_card_redemption_settlements');
        Schema::dropIfExists('gift_card_ledgers');
        Schema::dropIfExists('gift_card_issuances');
    }
};

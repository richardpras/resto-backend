<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_membership_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('name', 120);
            $table->string('code', 64)->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->decimal('min_lifetime_spend', 18, 2)->default(0);
            $table->unsignedInteger('min_lifetime_visits')->default(0);
            $table->decimal('points_multiplier', 8, 4)->default(1);
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['outlet_id', 'is_active', 'priority'], 'loyalty_tiers_scope_priority_index');
            $table->index(['outlet_id', 'min_lifetime_spend', 'min_lifetime_visits'], 'loyalty_tiers_threshold_index');
        });

        Schema::create('loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->uuid('customer_uuid');
            $table->uuid('global_customer_uuid');
            $table->string('name', 160);
            $table->string('phone', 32)->nullable();
            $table->string('email', 190)->nullable();
            $table->unsignedBigInteger('points_balance')->default(0);
            $table->unsignedBigInteger('lifetime_points_earned')->default(0);
            $table->unsignedBigInteger('lifetime_points_redeemed')->default(0);
            $table->decimal('lifetime_spend', 18, 2)->default(0);
            $table->unsignedInteger('lifetime_visits')->default(0);
            $table->foreignId('current_tier_id')->nullable()->constrained('loyalty_membership_tiers')->nullOnDelete();
            $table->foreignId('merged_into_account_id')->nullable()->constrained('loyalty_accounts')->nullOnDelete();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'customer_uuid'], 'loyalty_accounts_outlet_customer_uuid_unique');
            $table->index(['global_customer_uuid', 'outlet_id'], 'loyalty_accounts_global_uuid_scope_index');
            $table->index(['outlet_id', 'points_balance'], 'loyalty_accounts_balance_index');
        });

        Schema::create('loyalty_points_ledgers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained('loyalty_accounts')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120);
            $table->string('transaction_type', 40);
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->bigInteger('points_delta');
            $table->unsignedBigInteger('balance_before');
            $table->unsignedBigInteger('balance_after');
            $table->decimal('spend_amount', 18, 2)->default(0);
            $table->unsignedInteger('visit_increment')->default(0);
            $table->json('meta')->nullable();
            $table->timestamp('client_occurred_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('stale_rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'idempotency_key'], 'loyalty_ledger_outlet_idempotency_unique');
            $table->index(['loyalty_account_id', 'created_at'], 'loyalty_ledger_account_created_index');
        });

        Schema::create('loyalty_reward_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loyalty_account_id')->constrained('loyalty_accounts')->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('loyalty_points_ledgers')->nullOnDelete();
            $table->string('idempotency_key', 120);
            $table->string('reward_code', 64);
            $table->unsignedBigInteger('points_cost');
            $table->string('status', 24)->default('created');
            $table->json('meta')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('stale_rejected_at')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'idempotency_key'], 'loyalty_redemption_outlet_idempotency_unique');
            $table->index(['loyalty_account_id', 'status', 'created_at'], 'loyalty_redemptions_account_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_reward_redemptions');
        Schema::dropIfExists('loyalty_points_ledgers');
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('loyalty_membership_tiers');
    }
};

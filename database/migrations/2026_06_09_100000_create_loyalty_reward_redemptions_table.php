<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('loyalty_reward_redemptions')
            && Schema::hasColumn('loyalty_reward_redemptions', 'loyalty_account_id')
        ) {
            Schema::rename('loyalty_reward_redemptions', 'loyalty_account_reward_redemptions');
        }

        if (
            Schema::hasTable('loyalty_reward_redemptions')
            && ! Schema::hasColumn('loyalty_reward_redemptions', 'member_id')
        ) {
            Schema::dropIfExists('loyalty_reward_redemptions');
        }

        if (Schema::hasTable('loyalty_reward_redemptions')) {
            return;
        }

        Schema::create('loyalty_reward_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('member_id');
            $table->unsignedBigInteger('reward_id');
            $table->unsignedInteger('points_spent');
            $table->string('status', 32)->default('issued');
            $table->timestamp('issued_at');
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('outlet_id', 'member_loyalty_reward_redemptions_outlet_fk')
                ->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('member_id', 'member_loyalty_reward_redemptions_member_fk')
                ->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('reward_id', 'member_loyalty_reward_redemptions_reward_fk')
                ->references('id')->on('loyalty_rewards')->restrictOnDelete();

            $table->index(['member_id', 'status'], 'member_loyalty_reward_redemptions_member_status_idx');
            $table->index(['outlet_id', 'status'], 'member_loyalty_reward_redemptions_outlet_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_reward_redemptions');

        if (
            Schema::hasTable('loyalty_account_reward_redemptions')
            && ! Schema::hasTable('loyalty_reward_redemptions')
        ) {
            Schema::rename('loyalty_account_reward_redemptions', 'loyalty_reward_redemptions');
        }
    }
};

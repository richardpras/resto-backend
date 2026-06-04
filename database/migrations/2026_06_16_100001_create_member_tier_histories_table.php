<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_tier_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('tier_id')->constrained('loyalty_tiers')->restrictOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('removed_at')->nullable();
            $table->string('reason', 64);
            $table->timestamps();

            $table->index(['member_id', 'removed_at'], 'member_tier_histories_member_active_idx');
            $table->index(['outlet_id', 'tier_id', 'removed_at'], 'member_tier_histories_outlet_tier_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_tier_histories');
    }
};

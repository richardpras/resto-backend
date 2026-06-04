<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_campaign_audiences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('loyalty_campaigns')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['campaign_id', 'member_id'], 'loyalty_campaign_audiences_campaign_member_unique');
            $table->index('campaign_id', 'loyalty_campaign_audiences_campaign_idx');
            $table->index('member_id', 'loyalty_campaign_audiences_member_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_campaign_audiences');
    }
};

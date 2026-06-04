<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('segment_id')->constrained('member_segments')->restrictOnDelete();
            $table->string('campaign_type', 64)->default('audience');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'loyalty_campaigns_outlet_code_unique');
            $table->index(['outlet_id', 'status'], 'loyalty_campaigns_outlet_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_campaigns');
    }
};

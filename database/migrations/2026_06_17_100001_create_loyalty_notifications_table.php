<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('channel', 32);
            $table->string('title');
            $table->text('content');
            $table->string('status', 32)->default('pending');
            $table->json('payload_json')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'member_id', 'created_at'], 'loyalty_notifications_member_idx');
            $table->index(['outlet_id', 'event_type', 'status'], 'loyalty_notifications_analytics_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_notifications');
    }
};

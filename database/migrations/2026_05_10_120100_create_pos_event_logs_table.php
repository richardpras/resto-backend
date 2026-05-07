<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_event_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event_type', 120);
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('entity_id');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['outlet_id', 'occurred_at']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_event_logs');
    }
};

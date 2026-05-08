<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('device_key', 120);
            $table->string('display_label', 255)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('session_metadata')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('reconnect_count')->default(0);
            $table->timestamps();

            $table->unique(['outlet_id', 'device_key']);
            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_devices');
    }
};

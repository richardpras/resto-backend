<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_sync_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('terminal_device_id')->nullable()->constrained('terminal_devices')->nullOnDelete();
            $table->string('operation_type', 80);
            $table->string('fingerprint', 128);
            $table->json('payload')->nullable();
            $table->string('status', 32);
            $table->json('outcome_summary')->nullable();
            $table->string('failure_message', 512)->nullable();
            $table->string('conflict_type', 64)->nullable();
            $table->json('conflict_detail')->nullable();
            $table->string('duplicate_recommendation', 255)->nullable();
            $table->timestamp('client_occurred_at')->nullable();
            $table->timestamp('server_applied_at')->nullable();
            $table->unsignedInteger('duplicate_replay_hits')->default(0);
            $table->timestamps();

            $table->unique(['outlet_id', 'fingerprint']);
            $table->index(['outlet_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_sync_operations');
    }
};

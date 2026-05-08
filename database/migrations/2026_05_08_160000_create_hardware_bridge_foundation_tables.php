<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardware_bridge_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('device_key', 120);
            $table->string('display_label', 255)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->unsignedInteger('reconnect_count')->default(0);
            $table->timestamps();

            $table->unique(['outlet_id', 'device_key']);
            $table->index(['outlet_id', 'status']);
        });

        Schema::create('hardware_device_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('hardware_bridge_device_id')->constrained('hardware_bridge_devices')->cascadeOnDelete();
            $table->string('session_token', 80)->unique();
            $table->string('status', 32)->default('open');
            $table->json('metadata')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->unsignedInteger('reconnect_count')->default(0);
            $table->timestamps();

            $table->index(['outlet_id', 'status', 'opened_at']);
        });

        Schema::create('printer_device_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('hardware_bridge_device_id')->nullable()->constrained('hardware_bridge_devices')->nullOnDelete();
            $table->string('printer_code', 80);
            $table->string('name', 160);
            $table->string('connection_type', 32)->default('bridge');
            $table->string('status', 32)->default('unknown');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'printer_code']);
            $table->index(['outlet_id', 'status']);
        });

        Schema::create('hardware_command_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('hardware_bridge_device_id')->constrained('hardware_bridge_devices')->cascadeOnDelete();
            $table->foreignId('hardware_device_session_id')->nullable()->constrained('hardware_device_sessions')->nullOnDelete();
            $table->string('command_type', 64);
            $table->string('status', 32)->default('queued');
            $table->string('idempotency_key', 128);
            $table->json('payload')->nullable();
            $table->json('ack_payload')->nullable();
            $table->json('nack_payload')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_retries')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->timestamp('nacked_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->string('last_error_message', 512)->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'idempotency_key']);
            $table->index(['outlet_id', 'status', 'created_at']);
        });

        Schema::create('hardware_device_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('hardware_bridge_device_id')->nullable()->constrained('hardware_bridge_devices')->nullOnDelete();
            $table->foreignId('hardware_device_session_id')->nullable()->constrained('hardware_device_sessions')->nullOnDelete();
            $table->string('event_type', 80);
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['outlet_id', 'event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardware_device_events');
        Schema::dropIfExists('hardware_command_logs');
        Schema::dropIfExists('printer_device_profiles');
        Schema::dropIfExists('hardware_device_sessions');
        Schema::dropIfExists('hardware_bridge_devices');
    }
};

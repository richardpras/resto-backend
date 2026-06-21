<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hardware_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('display_label', 255)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('consumed_device_id')->nullable()->constrained('hardware_bridge_devices')->nullOnDelete();
            $table->timestamps();

            $table->index(['outlet_id', 'expires_at']);
            $table->index(['code_hash', 'consumed_at']);
        });

        Schema::create('hardware_device_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hardware_bridge_device_id')->unique()->constrained('hardware_bridge_devices')->cascadeOnDelete();
            $table->string('token_hash', 64);
            $table->string('refresh_token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('refresh_expires_at');
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['token_hash', 'revoked_at']);
            $table->index(['refresh_token_hash', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hardware_device_credentials');
        Schema::dropIfExists('hardware_pairing_codes');
    }
};

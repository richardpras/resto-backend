<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->string('code', 64);
            $table->string('name', 120);
            $table->string('station', 64)->nullable()->index();
            $table->string('connection_type', 32)->default('agent');
            $table->string('device_identifier', 190)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('health_status', 32)->default('unknown')->index();
            $table->string('queue_state', 32)->default('idle')->index();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('retry_policy')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_profiles');
    }
};

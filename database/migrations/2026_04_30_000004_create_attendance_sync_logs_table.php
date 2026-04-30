<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('external_ref')->unique();
            $table->string('payload_hash');
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->string('status');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_sync_logs');
    }
};

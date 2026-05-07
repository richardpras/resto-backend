<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->string('idempotency_key', 120);
            $table->string('request_hash', 64);
            $table->timestamp('processed_at');
            $table->timestamps();

            $table->unique(['scope', 'idempotency_key']);
            $table->index(['scope', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_idempotency_keys');
    }
};

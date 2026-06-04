<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_brevo_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->unique()->constrained('outlets')->cascadeOnDelete();
            $table->string('api_key')->nullable();
            $table->string('sender_email')->nullable();
            $table->string('sender_name')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_brevo_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_payment_method_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('payment_method_code', 64);
            $table->string('type', 32);
            $table->string('provider', 64)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'payment_method_code'], 'outlet_payment_method_code_unique');
            $table->index(['outlet_id', 'enabled', 'display_order'], 'outlet_payment_method_checkout_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_payment_method_configs');
    }
};

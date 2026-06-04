<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_automations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('trigger_type', 64);
            $table->json('condition_json')->nullable();
            $table->string('action_type', 64);
            $table->json('action_config_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'loyalty_automations_outlet_code_unique');
            $table->index(['outlet_id', 'trigger_type', 'is_active'], 'loyalty_automations_outlet_trigger_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_automations');
    }
};

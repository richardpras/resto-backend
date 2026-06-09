<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_recipe_cost_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->decimal('yield_percent', 8, 4)->default(100);
            $table->decimal('waste_percent', 8, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('menu_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_recipe_cost_settings');
    }
};

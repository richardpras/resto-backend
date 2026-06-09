<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_recipe_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('recipe_version_id')->nullable()->constrained('recipe_versions')->nullOnDelete();
            $table->unsignedBigInteger('menu_item_id');
            $table->unsignedInteger('version_number');
            $table->json('recipe_snapshot_json');
            $table->timestamp('created_at')->useCurrent();

            $table->unique('order_item_id');
            $table->index(['menu_item_id', 'recipe_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_recipe_snapshots');
    }
};

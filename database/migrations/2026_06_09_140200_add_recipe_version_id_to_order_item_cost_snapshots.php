<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_item_cost_snapshots', function (Blueprint $table): void {
            $table->foreignId('recipe_version_id')
                ->nullable()
                ->after('menu_item_id')
                ->constrained('recipe_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_item_cost_snapshots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('recipe_version_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_optimization_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('menu_item_id');
            $table->string('recommendation_type', 40);
            $table->json('recommendation_json');
            $table->decimal('projected_margin_percent', 10, 4)->default(0);
            $table->decimal('projected_profit_increase', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(
                ['snapshot_date', 'outlet_id', 'menu_item_id', 'recommendation_type'],
                'mo_snapshots_date_outlet_menu_type_unique',
            );
            $table->index(['outlet_id', 'snapshot_date']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_optimization_snapshots');
    }
};

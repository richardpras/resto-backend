<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_analytics_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('outlet_id');
            $table->decimal('average_food_cost_percent', 10, 4)->default(0);
            $table->decimal('average_margin_percent', 10, 4)->default(0);
            $table->decimal('inventory_value', 14, 4)->default(0);
            $table->decimal('daily_cogs', 14, 4)->default(0);
            $table->decimal('production_efficiency_percent', 10, 4)->default(0);
            $table->decimal('total_sales', 14, 4)->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            $table->timestamps();

            $table->unique(['snapshot_date', 'outlet_id']);
            $table->index('outlet_id');
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_analytics_snapshots');
    }
};

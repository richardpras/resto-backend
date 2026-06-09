<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('outlet_id');
            $table->decimal('total_revenue', 14, 4)->default(0);
            $table->decimal('food_cost_percent', 10, 4)->default(0);
            $table->decimal('average_margin_percent', 10, 4)->default(0);
            $table->unsignedInteger('star_count')->default(0);
            $table->unsignedInteger('puzzle_count')->default(0);
            $table->unsignedInteger('plowhorse_count')->default(0);
            $table->unsignedInteger('dog_count')->default(0);
            $table->unsignedInteger('active_alerts')->default(0);
            $table->unsignedInteger('critical_alerts')->default(0);
            $table->unsignedInteger('optimization_opportunities')->default(0);
            $table->decimal('forecast_revenue', 14, 4)->default(0);
            $table->decimal('forecast_margin', 14, 4)->default(0);
            $table->decimal('inventory_value', 14, 4)->default(0);
            $table->decimal('health_score', 8, 4)->default(0);
            $table->timestamps();

            $table->unique(['snapshot_date', 'outlet_id'], 'dash_snapshots_date_outlet_unique');
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_snapshots');
    }
};

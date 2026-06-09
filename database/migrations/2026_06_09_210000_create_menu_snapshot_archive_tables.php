<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_snapshot_archives', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_snapshot_id');
            $table->date('snapshot_date');
            $table->unsignedBigInteger('outlet_id');
            $table->decimal('average_food_cost_percent', 10, 4)->default(0);
            $table->decimal('average_margin_percent', 10, 4)->default(0);
            $table->decimal('inventory_value', 14, 4)->default(0);
            $table->decimal('daily_cogs', 14, 4)->default(0);
            $table->decimal('production_efficiency_percent', 10, 4)->default(0);
            $table->decimal('total_sales', 14, 4)->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->unique('source_snapshot_id', 'analytics_archives_source_unique');
            $table->index(['outlet_id', 'snapshot_date']);
        });

        Schema::create('engineering_snapshot_archives', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_snapshot_id');
            $table->date('snapshot_date');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('menu_item_id');
            $table->decimal('quantity_sold', 14, 4)->default(0);
            $table->decimal('popularity_percent', 10, 4)->default(0);
            $table->decimal('contribution_margin', 14, 4)->default(0);
            $table->decimal('margin_percent', 10, 4)->default(0);
            $table->string('classification', 20);
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->unique('source_snapshot_id', 'engineering_archives_source_unique');
            $table->index(['outlet_id', 'snapshot_date']);
        });

        Schema::create('optimization_snapshot_archives', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_snapshot_id');
            $table->date('snapshot_date');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('menu_item_id');
            $table->string('recommendation_type', 40);
            $table->json('recommendation_json');
            $table->decimal('projected_margin_percent', 10, 4)->default(0);
            $table->decimal('projected_profit_increase', 14, 4)->default(0);
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->unique('source_snapshot_id', 'optimization_archives_source_unique');
            $table->index(['outlet_id', 'snapshot_date']);
        });

        Schema::create('automation_snapshot_archives', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_snapshot_id');
            $table->date('snapshot_date');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedInteger('alerts_generated')->default(0);
            $table->unsignedInteger('critical_alerts')->default(0);
            $table->unsignedInteger('warnings')->default(0);
            $table->unsignedInteger('recommendations_generated')->default(0);
            $table->unsignedInteger('resolved_alerts')->default(0);
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->unique('source_snapshot_id', 'automation_archives_source_unique');
            $table->index(['outlet_id', 'snapshot_date']);
        });

        Schema::create('forecast_snapshot_archives', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_snapshot_id');
            $table->date('snapshot_date');
            $table->date('forecast_date');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('menu_item_id')->nullable();
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->string('forecast_type', 40);
            $table->decimal('predicted_quantity', 14, 4)->default(0);
            $table->decimal('predicted_revenue', 14, 4)->default(0);
            $table->decimal('predicted_margin', 14, 4)->default(0);
            $table->decimal('confidence_score', 8, 4)->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamp('archived_at');
            $table->timestamps();

            $table->unique('source_snapshot_id', 'forecast_archives_source_unique');
            $table->index(['outlet_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshot_archives');
        Schema::dropIfExists('automation_snapshot_archives');
        Schema::dropIfExists('optimization_snapshot_archives');
        Schema::dropIfExists('engineering_snapshot_archives');
        Schema::dropIfExists('analytics_snapshot_archives');
    }
};

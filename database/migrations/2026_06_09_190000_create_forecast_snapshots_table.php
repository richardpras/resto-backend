<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_snapshots', function (Blueprint $table): void {
            $table->id();
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
            $table->timestamps();

            $table->unique(
                ['snapshot_date', 'forecast_date', 'outlet_id', 'forecast_type', 'menu_item_id', 'inventory_item_id'],
                'fc_snapshots_scope_unique',
            );
            $table->index(['outlet_id', 'forecast_date', 'forecast_type']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->nullOnDelete();
            $table->foreign('inventory_item_id')->references('id')->on('ingredients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshots');
    }
};

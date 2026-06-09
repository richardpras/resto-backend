<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_engineering_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('menu_item_id');
            $table->decimal('quantity_sold', 14, 4)->default(0);
            $table->decimal('popularity_percent', 10, 4)->default(0);
            $table->decimal('contribution_margin', 14, 4)->default(0);
            $table->decimal('margin_percent', 10, 4)->default(0);
            $table->string('classification', 20);
            $table->timestamps();

            $table->unique(['snapshot_date', 'outlet_id', 'menu_item_id'], 'me_snapshots_date_outlet_menu_unique');
            $table->index(['outlet_id', 'snapshot_date']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_engineering_snapshots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_outlets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('menu_item_id')->constrained('menu_items')->cascadeOnDelete();
            $table->string('outlet_id', 64);
            $table->boolean('is_active')->default(true);
            $table->decimal('price_override', 14, 2)->nullable();
            $table->string('name_override', 255)->nullable();
            $table->string('receipt_name', 255)->nullable();
            $table->timestamps();

            $table->unique(['menu_item_id', 'outlet_id']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_outlets');
    }
};

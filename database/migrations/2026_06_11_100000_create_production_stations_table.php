<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_stations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->string('code', 64);
            $table->string('name', 120);
            $table->string('type', 64)->index();
            $table->unsignedSmallInteger('display_order')->default(100)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('kds_enabled')->default(true);
            $table->boolean('print_enabled')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_stations');
    }
};

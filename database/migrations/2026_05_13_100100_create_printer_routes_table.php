<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('printer_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->unsignedBigInteger('printer_profile_id')->index();
            $table->string('print_type', 32)->index();
            $table->string('station', 64)->nullable()->index();
            $table->string('category', 120)->nullable()->index();
            $table->unsignedSmallInteger('priority')->default(100)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('printer_profile_id')->references('id')->on('printer_profiles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('printer_routes');
    }
};

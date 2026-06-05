<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouses')) {
            return;
        }

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 16)->default('outlet');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};

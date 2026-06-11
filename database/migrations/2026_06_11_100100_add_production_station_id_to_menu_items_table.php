<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('production_station_id')->nullable()->after('category')->index();
            $table->foreign('production_station_id')->references('id')->on('production_stations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropForeign(['production_station_id']);
            $table->dropColumn('production_station_id');
        });
    }
};

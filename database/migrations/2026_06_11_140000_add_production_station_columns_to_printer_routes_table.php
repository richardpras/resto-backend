<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printer_routes', function (Blueprint $table): void {
            $table->unsignedBigInteger('production_station_id')->nullable()->after('item_id')->index();
            $table->string('station_code', 64)->nullable()->after('production_station_id')->index();
            $table->foreign('production_station_id')->references('id')->on('production_stations')->nullOnDelete();
            $table->index(['outlet_id', 'print_type', 'production_station_id']);
            $table->index(['outlet_id', 'print_type', 'station_code']);
        });
    }

    public function down(): void
    {
        Schema::table('printer_routes', function (Blueprint $table): void {
            $table->dropForeign(['production_station_id']);
            $table->dropIndex(['outlet_id', 'print_type', 'production_station_id']);
            $table->dropIndex(['outlet_id', 'print_type', 'station_code']);
            $table->dropColumn(['production_station_id', 'station_code']);
        });
    }
};

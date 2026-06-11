<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kitchen_tickets', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
            $table->dropUnique(['order_id']);
            $table->index('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('production_station_id')->nullable()->after('order_id')->index();
            $table->string('station_code', 64)->nullable()->after('production_station_id')->index();
            $table->string('station_name', 120)->nullable()->after('station_code');
            $table->index(['order_id', 'production_station_id']);
            $table->foreign('production_station_id')->references('id')->on('production_stations')->nullOnDelete();
        });

        Schema::table('kitchen_ticket_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('production_station_id')->nullable()->after('order_item_id')->index();
            $table->string('station_code', 64)->nullable()->after('production_station_id');
            $table->string('station_name', 120)->nullable()->after('station_code');
            $table->foreign('production_station_id')->references('id')->on('production_stations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kitchen_ticket_items', function (Blueprint $table): void {
            $table->dropForeign(['production_station_id']);
            $table->dropColumn(['production_station_id', 'station_code', 'station_name']);
        });

        Schema::table('kitchen_tickets', function (Blueprint $table): void {
            $table->dropForeign(['production_station_id']);
            $table->dropForeign(['order_id']);
            $table->dropIndex(['order_id', 'production_station_id']);
            $table->dropIndex(['order_id']);
            $table->dropColumn(['production_station_id', 'station_code', 'station_name']);
            $table->unique('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }
};

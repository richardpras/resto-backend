<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printer_routes', function (Blueprint $table): void {
            $table->string('route_scope', 32)->default('default')->after('print_type')->index();
            $table->unsignedBigInteger('item_id')->nullable()->after('route_scope')->index();
        });
    }

    public function down(): void
    {
        Schema::table('printer_routes', function (Blueprint $table): void {
            $table->dropColumn(['route_scope', 'item_id']);
        });
    }
};

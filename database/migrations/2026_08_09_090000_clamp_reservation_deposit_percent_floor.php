<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff/public reservation DP uses outlet deposit_percent with a 50% floor.
 * Clamp existing rows below 50 and prefer percent mode when percent is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outlet_reservation_settings')) {
            return;
        }

        DB::table('outlet_reservation_settings')
            ->whereNotNull('deposit_percent')
            ->where('deposit_percent', '<', 50)
            ->update(['deposit_percent' => 50]);

        DB::table('outlet_reservation_settings')
            ->whereNull('deposit_percent')
            ->where('deposit_mode', 'percent')
            ->update(['deposit_percent' => 50]);
    }

    public function down(): void
    {
        // Irreversible data clamp.
    }
};

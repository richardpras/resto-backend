<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlet_reservation_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('invite_link_expiry_hours')->default(24)->after('deposit_review_timeout_hours');
        });
    }

    public function down(): void
    {
        Schema::table('outlet_reservation_settings', function (Blueprint $table) {
            $table->dropColumn('invite_link_expiry_hours');
        });
    }
};

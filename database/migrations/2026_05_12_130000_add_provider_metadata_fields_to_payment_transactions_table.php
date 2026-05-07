<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->string('deeplink_url', 255)->nullable()->after('qr_string');
            $table->json('provider_metadata_snapshot')->nullable()->after('payload_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropColumn(['deeplink_url', 'provider_metadata_snapshot']);
        });
    }
};

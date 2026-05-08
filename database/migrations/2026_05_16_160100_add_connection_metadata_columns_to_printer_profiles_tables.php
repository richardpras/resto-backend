<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printer_profiles', function (Blueprint $table): void {
            $table->string('ip_address', 45)->nullable()->after('device_identifier');
            $table->string('mac_address', 32)->nullable()->after('ip_address');
            $table->string('bluetooth_name', 120)->nullable()->after('mac_address');
            $table->string('bluetooth_address', 32)->nullable()->after('bluetooth_name');
            $table->string('pairing_state', 32)->nullable()->after('bluetooth_address');
            $table->timestamp('last_connected_at')->nullable()->after('pairing_state');
            $table->json('reconnect_metadata')->nullable()->after('last_connected_at');
            $table->json('signal_metadata')->nullable()->after('reconnect_metadata');
        });

        Schema::table('printer_device_profiles', function (Blueprint $table): void {
            $table->string('device_identifier', 190)->nullable()->after('connection_type');
            $table->string('ip_address', 45)->nullable()->after('device_identifier');
            $table->string('mac_address', 32)->nullable()->after('ip_address');
            $table->string('bluetooth_name', 120)->nullable()->after('mac_address');
            $table->string('bluetooth_address', 32)->nullable()->after('bluetooth_name');
            $table->string('pairing_state', 32)->nullable()->after('bluetooth_address');
            $table->timestamp('last_connected_at')->nullable()->after('pairing_state');
            $table->json('reconnect_metadata')->nullable()->after('last_connected_at');
            $table->json('signal_metadata')->nullable()->after('reconnect_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('printer_device_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'device_identifier',
                'ip_address',
                'mac_address',
                'bluetooth_name',
                'bluetooth_address',
                'pairing_state',
                'last_connected_at',
                'reconnect_metadata',
                'signal_metadata',
            ]);
        });

        Schema::table('printer_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'ip_address',
                'mac_address',
                'bluetooth_name',
                'bluetooth_address',
                'pairing_state',
                'last_connected_at',
                'reconnect_metadata',
                'signal_metadata',
            ]);
        });
    }
};

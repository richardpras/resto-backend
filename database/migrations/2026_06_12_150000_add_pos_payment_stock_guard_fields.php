<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->boolean('enforce_stock_on_sale')->default(true)->after('enable_qr_ordering');
        });

        Schema::table('pos_idempotency_keys', function (Blueprint $table): void {
            $table->json('response_payload')->nullable()->after('request_hash');
        });

        Schema::create('outlet_inventory_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->unique();
            $table->boolean('enforce_stock_on_sale')->nullable();
            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_inventory_settings');

        Schema::table('pos_idempotency_keys', function (Blueprint $table): void {
            $table->dropColumn('response_payload');
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('enforce_stock_on_sale');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->string('checkout_url', 255)->nullable()->after('payment_method');
            $table->text('qr_string')->nullable()->after('checkout_url');
            $table->string('va_number', 64)->nullable()->after('qr_string');
            $table->timestamp('expiry_time')->nullable()->after('va_number');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropColumn(['checkout_url', 'qr_string', 'va_number', 'expiry_time']);
        });
    }
};

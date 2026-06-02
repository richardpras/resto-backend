<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_webhook_receipts', function (Blueprint $table): void {
            $table->longText('signed_payload')->nullable()->after('headers');
        });
    }

    public function down(): void
    {
        Schema::table('payment_webhook_receipts', function (Blueprint $table): void {
            $table->dropColumn('signed_payload');
        });
    }
};

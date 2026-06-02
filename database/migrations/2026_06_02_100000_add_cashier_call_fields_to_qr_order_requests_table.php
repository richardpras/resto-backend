<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->timestamp('cashier_called_at')->nullable()->after('expires_at');
            $table->unsignedInteger('cashier_call_count')->default(0)->after('cashier_called_at');
        });
    }

    public function down(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->dropColumn(['cashier_called_at', 'cashier_call_count']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->boolean('require_customer_approval_for_adjustments')->default(false)->after('enable_call_cashier');
        });

        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->timestamp('customer_served_at')->nullable()->after('opened_in_pos_by_user_id');
            $table->string('last_cashier_call_reason', 64)->nullable()->after('cashier_call_count');
            $table->string('customer_approval_status', 32)->nullable()->after('adjustment_log');
        });
    }

    public function down(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->dropColumn(['customer_served_at', 'last_cashier_call_reason', 'customer_approval_status']);
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('require_customer_approval_for_adjustments');
        });
    }
};

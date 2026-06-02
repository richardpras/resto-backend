<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->string('decision_mode', 32)->nullable()->after('status');
            $table->index(['outlet_id', 'table_id', 'status'], 'qr_order_requests_outlet_table_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->dropIndex('qr_order_requests_outlet_table_status_idx');
            $table->dropColumn('decision_mode');
        });
    }
};

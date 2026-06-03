<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->unsignedBigInteger('linked_order_id')->nullable()->after('no_show_at');
            $table->dateTime('service_started_at')->nullable()->after('linked_order_id');

            $table->foreign('linked_order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropForeign(['linked_order_id']);
            $table->dropColumn(['linked_order_id', 'service_started_at']);
        });
    }
};

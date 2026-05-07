<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'pos_session_id')) {
                $table->foreignId('pos_session_id')
                    ->nullable()
                    ->after('outlet_id')
                    ->constrained('pos_sessions')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'order_channel')) {
                $table->string('order_channel', 32)->nullable()->after('source');
            }

            if (! Schema::hasColumn('orders', 'service_mode')) {
                $table->string('service_mode', 32)->nullable()->after('order_channel');
            }

            if (! Schema::hasColumn('orders', 'kitchen_status')) {
                $table->string('kitchen_status', 32)->default('queued')->after('payment_status');
            }
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['outlet_id', 'service_mode']);
            $table->index(['outlet_id', 'kitchen_status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['outlet_id', 'kitchen_status']);
            $table->dropIndex(['outlet_id', 'service_mode']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'kitchen_status')) {
                $table->dropColumn('kitchen_status');
            }
            if (Schema::hasColumn('orders', 'service_mode')) {
                $table->dropColumn('service_mode');
            }
            if (Schema::hasColumn('orders', 'order_channel')) {
                $table->dropColumn('order_channel');
            }
            if (Schema::hasColumn('orders', 'pos_session_id')) {
                $table->dropForeign(['pos_session_id']);
                $table->dropColumn('pos_session_id');
            }
        });
    }
};

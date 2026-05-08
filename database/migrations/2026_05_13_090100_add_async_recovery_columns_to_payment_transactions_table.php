<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('reconciliation_attempts')->default(0)->after('status');
            $table->timestamp('last_reconciled_at')->nullable()->after('expired_at');
            $table->timestamp('async_retry_after')->nullable()->after('last_reconciled_at');
            $table->text('last_async_error')->nullable()->after('async_retry_after');

            $table->index(['status', 'async_retry_after'], 'payment_tx_async_retry_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table): void {
            $table->dropIndex('payment_tx_async_retry_index');
            $table->dropColumn([
                'reconciliation_attempts',
                'last_reconciled_at',
                'async_retry_after',
                'last_async_error',
            ]);
        });
    }
};

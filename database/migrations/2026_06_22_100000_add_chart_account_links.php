<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('chart_account_id')->nullable()->after('is_default');
            $table->foreign('chart_account_id')->references('id')->on('accounts')->nullOnDelete();
        });

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->unsignedBigInteger('chart_account_id')->nullable()->after('status');
            $table->foreign('chart_account_id')->references('id')->on('accounts')->nullOnDelete();
        });

        Schema::table('outlet_payment_method_configs', function (Blueprint $table): void {
            $table->unsignedBigInteger('chart_account_id')->nullable()->after('settings');
            $table->foreign('chart_account_id')->references('id')->on('accounts')->nullOnDelete();
        });

        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->string('bank_account_id', 64)->nullable()->after('payment_method');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table): void {
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn('bank_account_id');
        });

        Schema::table('outlet_payment_method_configs', function (Blueprint $table): void {
            $table->dropForeign(['chart_account_id']);
            $table->dropColumn('chart_account_id');
        });

        Schema::table('payment_methods', function (Blueprint $table): void {
            $table->dropForeign(['chart_account_id']);
            $table->dropColumn('chart_account_id');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropForeign(['chart_account_id']);
            $table->dropColumn('chart_account_id');
        });
    }
};

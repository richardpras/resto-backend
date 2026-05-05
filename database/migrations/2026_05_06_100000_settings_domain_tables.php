<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->text('address')->nullable()->after('name');
            $table->string('phone', 64)->nullable()->after('address');
            $table->string('manager', 255)->nullable()->after('phone');
            $table->string('status', 16)->default('active')->after('manager');
            $table->string('logo', 2048)->nullable()->after('status');
            $table->string('invoice_prefix', 64)->nullable()->after('logo');
            $table->string('order_prefix', 64)->nullable()->after('invoice_prefix');
        });

        Schema::create('merchant_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 255);
            $table->string('business_type', 100);
            $table->text('address');
            $table->string('phone', 64);
            $table->string('email', 255);
            $table->string('currency', 8);
            $table->string('timezone', 64);
            $table->string('language', 16);
            $table->string('logo', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('taxes', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name', 255);
            $table->string('type', 16);
            $table->decimal('value', 14, 4);
            $table->boolean('apply_dine_in');
            $table->boolean('apply_takeaway');
            $table->boolean('inclusive');
            $table->string('status', 16);
            $table->timestamps();
        });

        Schema::create('setting_printers', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name', 255);
            $table->string('printer_type', 16);
            $table->string('connection', 16);
            $table->string('ip', 64)->nullable();
            $table->string('bluetooth_device', 255)->nullable();
            $table->string('outlet_id', 64);
            $table->json('assigned_categories')->nullable();
            $table->timestamps();

            $table->foreign('outlet_id')
                ->references('id')
                ->on('outlets')
                ->cascadeOnDelete();
        });

        Schema::create('payment_methods', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name', 255);
            $table->string('type', 16);
            $table->string('integration', 255)->nullable();
            $table->decimal('fee', 10, 4)->nullable();
            $table->string('status', 16);
            $table->timestamps();
        });

        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('bank_name', 255);
            $table->string('account_name', 255);
            $table->string('account_number', 64);
            $table->boolean('is_default');
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enable_split_bill');
            $table->boolean('enable_multi_payment');
            $table->boolean('confirm_before_payment');
            $table->boolean('enable_qr_ordering');
            $table->timestamps();
        });

        Schema::create('integration_settings', function (Blueprint $table): void {
            $table->id();
            $table->text('payment_gateway_key')->nullable();
            $table->text('webhook_url')->nullable();
            $table->text('print_agent_url')->nullable();
            $table->text('third_party_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('numbering_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('invoice_format', 128);
            $table->string('order_format', 128);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numbering_settings');
        Schema::dropIfExists('integration_settings');
        Schema::dropIfExists('system_settings');

        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('payment_methods');

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('setting_printers');

        Schema::dropIfExists('taxes');

        Schema::dropIfExists('merchant_settings');

        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn([
                'address',
                'phone',
                'manager',
                'status',
                'logo',
                'invoice_prefix',
                'order_prefix',
            ]);
        });

        Schema::enableForeignKeyConstraints();
    }
};

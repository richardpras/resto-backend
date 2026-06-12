<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->string('stock_enforcement_mode', 16)->default('deferred')->after('enforce_stock_on_sale');
        });

        Schema::table('outlet_inventory_settings', function (Blueprint $table): void {
            $table->string('stock_enforcement_mode', 16)->nullable()->after('enforce_stock_on_sale');
        });

        if (Schema::hasTable('system_settings') && Schema::hasColumn('system_settings', 'enforce_stock_on_sale')) {
            DB::table('system_settings')->update([
                'stock_enforcement_mode' => DB::raw("CASE WHEN enforce_stock_on_sale = 1 THEN 'strict' ELSE 'warning' END"),
            ]);
        }

        Schema::create('inventory_consumption_queue', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('order_id');
            $table->string('status', 32)->default('pending');
            $table->json('payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['outlet_id', 'status']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });

        Schema::create('inventory_incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->string('incident_type', 64);
            $table->string('severity', 16)->default('warning');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('expected_quantity', 14, 4)->nullable();
            $table->decimal('available_quantity', 14, 4)->nullable();
            $table->decimal('variance', 14, 4)->nullable();
            $table->string('status', 16)->default('open');
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status']);
            $table->index('incident_type');
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_incidents');
        Schema::dropIfExists('inventory_consumption_queue');

        Schema::table('outlet_inventory_settings', function (Blueprint $table): void {
            $table->dropColumn('stock_enforcement_mode');
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('stock_enforcement_mode');
        });
    }
};

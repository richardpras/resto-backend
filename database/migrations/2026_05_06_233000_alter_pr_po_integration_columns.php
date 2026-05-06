<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_request_items', 'fulfilled_qty')) {
                $table->decimal('fulfilled_qty', 14, 2)->default(0)->after('requested_qty');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_orders', 'source_pr_id')) {
                $table->foreignId('source_pr_id')->nullable()->after('purchase_request_id')->constrained('purchase_requests')->nullOnDelete();
            }
        });

        Schema::table('purchase_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_order_items', 'pr_item_id')) {
                $table->foreignId('pr_item_id')->nullable()->after('purchase_order_id')->constrained('purchase_request_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('purchase_order_items', 'requested_qty')) {
                $table->decimal('requested_qty', 14, 2)->default(0)->after('ordered_qty');
            }
            if (! Schema::hasColumn('purchase_order_items', 'is_from_pr')) {
                $table->boolean('is_from_pr')->default(true)->after('requested_qty');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_order_items', 'is_from_pr')) {
                $table->dropColumn('is_from_pr');
            }
            if (Schema::hasColumn('purchase_order_items', 'requested_qty')) {
                $table->dropColumn('requested_qty');
            }
            if (Schema::hasColumn('purchase_order_items', 'pr_item_id')) {
                $table->dropConstrainedForeignId('pr_item_id');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_orders', 'source_pr_id')) {
                $table->dropConstrainedForeignId('source_pr_id');
            }
        });

        Schema::table('purchase_request_items', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_request_items', 'fulfilled_qty')) {
                $table->dropColumn('fulfilled_qty');
            }
        });
    }
};

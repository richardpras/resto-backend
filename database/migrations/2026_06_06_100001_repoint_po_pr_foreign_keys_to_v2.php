<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function dropForeignIfExists(string $table, string $column): void
    {
        $database = Schema::getConnection()->getDatabaseName();
        $constraint = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->value('CONSTRAINT_NAME');

        if ($constraint) {
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`");
        }
    }

    public function up(): void
    {
        $validPrIds = Schema::hasTable('purchase_requests_v2')
            ? DB::table('purchase_requests_v2')->pluck('id')->all()
            : [];
        $validPrItemIds = Schema::hasTable('purchase_request_items_v2')
            ? DB::table('purchase_request_items_v2')->pluck('id')->all()
            : [];

        if (Schema::hasColumn('purchase_orders', 'purchase_request_id')) {
            DB::table('purchase_orders')
                ->whereNotNull('purchase_request_id')
                ->when($validPrIds !== [], fn ($q) => $q->whereNotIn('purchase_request_id', $validPrIds), fn ($q) => $q->whereNotNull('purchase_request_id'))
                ->update(['purchase_request_id' => null]);

            $this->dropForeignIfExists('purchase_orders', 'purchase_request_id');
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->foreign('purchase_request_id')->references('id')->on('purchase_requests_v2')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('purchase_orders', 'source_pr_id')) {
            DB::table('purchase_orders')
                ->whereNotNull('source_pr_id')
                ->when($validPrIds !== [], fn ($q) => $q->whereNotIn('source_pr_id', $validPrIds), fn ($q) => $q->whereNotNull('source_pr_id'))
                ->update(['source_pr_id' => null]);

            $this->dropForeignIfExists('purchase_orders', 'source_pr_id');
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->foreign('source_pr_id')->references('id')->on('purchase_requests_v2')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('purchase_order_items', 'pr_item_id')) {
            DB::table('purchase_order_items')
                ->whereNotNull('pr_item_id')
                ->when($validPrItemIds !== [], fn ($q) => $q->whereNotIn('pr_item_id', $validPrItemIds), fn ($q) => $q->whereNotNull('pr_item_id'))
                ->update(['pr_item_id' => null]);

            $this->dropForeignIfExists('purchase_order_items', 'pr_item_id');
            Schema::table('purchase_order_items', function (Blueprint $table): void {
                $table->foreign('pr_item_id')->references('id')->on('purchase_request_items_v2')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('purchase_order_items', 'pr_item_id')) {
            Schema::table('purchase_order_items', function (Blueprint $table): void {
                $table->dropForeign(['pr_item_id']);
            });
            Schema::table('purchase_order_items', function (Blueprint $table): void {
                $table->foreign('pr_item_id')->references('id')->on('purchase_request_items')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('purchase_orders', 'source_pr_id')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->dropForeign(['source_pr_id']);
            });
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->foreign('source_pr_id')->references('id')->on('purchase_requests')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('purchase_orders', 'purchase_request_id')) {
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->dropForeign(['purchase_request_id']);
            });
            Schema::table('purchase_orders', function (Blueprint $table): void {
                $table->foreign('purchase_request_id')->references('id')->on('purchase_requests')->nullOnDelete();
            });
        }
    }
};

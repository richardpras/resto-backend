<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_orders', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('purchase_orders', 'submitted_by')) {
                $table->unsignedBigInteger('submitted_by')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('purchase_orders', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('submitted_by');
            }
            if (! Schema::hasColumn('purchase_orders', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('purchase_orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('purchase_orders', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('purchase_orders', 'closed_at')) {
                $table->timestamp('closed_at')->nullable()->after('cancelled_by');
            }
            if (! Schema::hasColumn('purchase_orders', 'closed_by')) {
                $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
            }
        });

        DB::statement("ALTER TABLE purchase_orders MODIFY status VARCHAR(32) NOT NULL DEFAULT 'draft'");

        DB::table('purchase_orders')->where('status', 'sent')->update(['status' => 'approved']);
        DB::table('purchase_orders')->where('status', 'partial')->update(['status' => 'partially_received']);
        DB::table('purchase_orders')->where('status', 'completed')->update(['status' => 'received']);

        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM('draft','submitted','approved','partially_received','received','cancelled','closed') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::table('purchase_orders')->where('status', 'submitted')->update(['status' => 'draft']);
        DB::table('purchase_orders')->where('status', 'approved')->update(['status' => 'sent']);
        DB::table('purchase_orders')->where('status', 'partially_received')->update(['status' => 'partial']);
        DB::table('purchase_orders')->where('status', 'received')->update(['status' => 'completed']);
        DB::table('purchase_orders')->whereIn('status', ['cancelled', 'closed'])->update(['status' => 'draft']);

        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM('draft','sent','partial','completed') NOT NULL DEFAULT 'draft'");

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $columns = ['closed_by', 'closed_at', 'cancelled_by', 'cancelled_at', 'approved_by', 'approved_at', 'submitted_by', 'submitted_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('purchase_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

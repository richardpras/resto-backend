<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_close_runs', function (Blueprint $table): void {
            $table->date('shift_date')->nullable()->after('outlet_id');
            $table->decimal('sales_amount', 16, 2)->nullable()->after('result_snapshot');
            $table->decimal('cash_sales', 16, 2)->nullable();
            $table->decimal('non_cash_sales', 16, 2)->nullable();
            $table->decimal('opening_cash', 16, 2)->nullable();
            $table->decimal('cash_refunds', 16, 2)->nullable();
            $table->decimal('cash_expenses', 16, 2)->nullable();
            $table->decimal('cash_in', 16, 2)->nullable();
            $table->decimal('cash_out', 16, 2)->nullable();
            $table->decimal('expected_cash', 16, 2)->nullable();
            $table->decimal('actual_cash', 16, 2)->nullable();
            $table->unsignedInteger('inventory_variance')->default(0);
            $table->unsignedInteger('open_bill_count')->default(0);
            $table->unsignedInteger('open_pos_session_count')->default(0);
            $table->unsignedInteger('pending_qr_count')->default(0);
            $table->unsignedInteger('under_review_qr_count')->default(0);
            $table->unsignedInteger('linked_unpaid_qr_bill_count')->default(0);
            $table->unsignedInteger('pending_consumption_count')->default(0);
            $table->unsignedInteger('failed_accounting_posting_count')->default(0);
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->after('run_by_user_id');

            $table->index(['outlet_id', 'shift_date']);
        });

        DB::table('shift_close_runs')->where('status', 'started')->update(['status' => 'running']);
    }

    public function down(): void
    {
        Schema::table('shift_close_runs', function (Blueprint $table): void {
            $table->dropIndex(['outlet_id', 'shift_date']);
            $table->dropColumn([
                'shift_date',
                'sales_amount',
                'cash_sales',
                'non_cash_sales',
                'opening_cash',
                'cash_refunds',
                'cash_expenses',
                'cash_in',
                'cash_out',
                'expected_cash',
                'actual_cash',
                'inventory_variance',
                'open_bill_count',
                'open_pos_session_count',
                'pending_qr_count',
                'under_review_qr_count',
                'linked_unpaid_qr_bill_count',
                'pending_consumption_count',
                'failed_accounting_posting_count',
                'metadata',
                'created_by_user_id',
            ]);
        });

        DB::table('shift_close_runs')->where('status', 'running')->update(['status' => 'started']);
    }
};

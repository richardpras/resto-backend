<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_invoices', 'supplier_id')) {
                $table->unsignedBigInteger('supplier_id')->nullable()->after('goods_receiving_note_id');
            }
            if (! Schema::hasColumn('purchase_invoices', 'supplier_invoice_no')) {
                $table->string('supplier_invoice_no')->nullable()->after('number');
            }
            if (! Schema::hasColumn('purchase_invoices', 'due_date')) {
                $table->date('due_date')->nullable()->after('invoice_date');
            }
            if (! Schema::hasColumn('purchase_invoices', 'subtotal')) {
                $table->decimal('subtotal', 14, 2)->default(0)->after('due_date');
            }
            if (! Schema::hasColumn('purchase_invoices', 'tax_amount')) {
                $table->decimal('tax_amount', 14, 2)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('purchase_invoices', 'tax_percentage')) {
                $table->decimal('tax_percentage', 8, 2)->nullable()->after('tax_amount');
            }
            if (! Schema::hasColumn('purchase_invoices', 'discount_amount')) {
                $table->decimal('discount_amount', 14, 2)->default(0)->after('tax_percentage');
            }
            if (! Schema::hasColumn('purchase_invoices', 'total_amount')) {
                $table->decimal('total_amount', 14, 2)->default(0)->after('discount_amount');
            }
            if (! Schema::hasColumn('purchase_invoices', 'paid_amount')) {
                $table->decimal('paid_amount', 14, 2)->default(0)->after('total_amount');
            }
            if (! Schema::hasColumn('purchase_invoices', 'outstanding_amount')) {
                $table->decimal('outstanding_amount', 14, 2)->default(0)->after('paid_amount');
            }
            if (! Schema::hasColumn('purchase_invoices', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }
            if (! Schema::hasColumn('purchase_invoices', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('purchase_invoices', 'submitted_by')) {
                $table->unsignedBigInteger('submitted_by')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('purchase_invoices', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('submitted_by');
            }
            if (! Schema::hasColumn('purchase_invoices', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('purchase_invoices', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('approved_by');
            }
            if (! Schema::hasColumn('purchase_invoices', 'voided_by')) {
                $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            }
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_invoice_items', 'received_qty')) {
                $table->decimal('received_qty', 14, 2)->default(0)->after('ingredient_id');
            }
            if (! Schema::hasColumn('purchase_invoice_items', 'invoiced_qty')) {
                $table->decimal('invoiced_qty', 14, 2)->default(0)->after('received_qty');
            }
            if (! Schema::hasColumn('purchase_invoice_items', 'unit_cost')) {
                $table->decimal('unit_cost', 14, 4)->default(0)->after('invoiced_qty');
            }
            if (! Schema::hasColumn('purchase_invoice_items', 'line_subtotal')) {
                $table->decimal('line_subtotal', 14, 2)->default(0)->after('unit_cost');
            }
            if (! Schema::hasColumn('purchase_invoice_items', 'line_tax_amount')) {
                $table->decimal('line_tax_amount', 14, 2)->default(0)->after('line_subtotal');
            }
            if (! Schema::hasColumn('purchase_invoice_items', 'line_total')) {
                $table->decimal('line_total', 14, 2)->default(0)->after('line_tax_amount');
            }
        });

        DB::statement('UPDATE purchase_invoices SET subtotal = GREATEST(0, total - COALESCE(tax, 0)) WHERE subtotal = 0 AND total > 0');
        DB::statement('UPDATE purchase_invoices SET tax_amount = COALESCE(tax, 0)');
        DB::statement('UPDATE purchase_invoices SET total_amount = total');
        DB::statement('UPDATE purchase_invoices SET outstanding_amount = GREATEST(0, total_amount - paid_amount)');

        DB::statement("UPDATE purchase_invoices SET status = 'approved' WHERE status = 'unpaid'");
        DB::statement("UPDATE purchase_invoices SET status = 'partially_paid' WHERE status = 'partial'");
        DB::statement('UPDATE purchase_invoices SET approved_at = created_at WHERE status IN ("approved", "partially_paid", "paid") AND approved_at IS NULL');

        DB::statement('UPDATE purchase_invoice_items SET invoiced_qty = qty WHERE invoiced_qty = 0 AND qty > 0');
        DB::statement('UPDATE purchase_invoice_items SET unit_cost = unit_price WHERE unit_cost = 0 AND unit_price > 0');
        DB::statement('UPDATE purchase_invoice_items SET line_subtotal = qty * unit_price WHERE line_subtotal = 0');
        DB::statement('UPDATE purchase_invoice_items SET line_total = line_subtotal WHERE line_total = 0');

        DB::table('purchase_invoices')
            ->whereNull('supplier_id')
            ->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $supplierId = DB::table('purchase_orders')->where('id', $row->purchase_order_id)->value('supplier_id');
                    if ($supplierId !== null) {
                        DB::table('purchase_invoices')->where('id', $row->id)->update(['supplier_id' => $supplierId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table): void {
            foreach (['line_total', 'line_tax_amount', 'line_subtotal', 'unit_cost', 'invoiced_qty', 'received_qty'] as $column) {
                if (Schema::hasColumn('purchase_invoice_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('purchase_invoices', function (Blueprint $table): void {
            foreach ([
                'voided_by', 'voided_at', 'approved_by', 'approved_at', 'submitted_by', 'submitted_at',
                'notes', 'outstanding_amount', 'paid_amount', 'total_amount', 'discount_amount',
                'tax_percentage', 'tax_amount', 'subtotal', 'due_date', 'supplier_invoice_no', 'supplier_id',
            ] as $column) {
                if (Schema::hasColumn('purchase_invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

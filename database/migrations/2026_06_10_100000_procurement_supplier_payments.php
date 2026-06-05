<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->string('payment_no')->unique();
            $table->date('payment_date');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'giro', 'check', 'other'])->default('cash');
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('allocated_amount', 14, 2)->default(0);
            $table->decimal('unallocated_amount', 14, 2)->default(0);
            $table->enum('status', ['draft', 'approved', 'posted', 'void'])->default('draft');
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained('purchase_invoices')->restrictOnDelete();
            $table->decimal('allocated_amount', 14, 2);
            $table->timestamps();

            $table->unique(['supplier_payment_id', 'purchase_invoice_id'], 'supplier_payment_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
    }
};

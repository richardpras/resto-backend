<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_match_configs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->decimal('quantity_tolerance_percent', 8, 4)->default(0);
            $table->decimal('price_tolerance_percent', 8, 4)->default(3);
            $table->decimal('amount_tolerance_percent', 8, 4)->default(3);
            $table->boolean('auto_approve_within_tolerance')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('outlet_id');
            $table->index('is_active');
        });

        Schema::create('procurement_match_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('goods_receipt_id');
            $table->unsignedBigInteger('invoice_id');
            $table->string('match_status', 32);
            $table->decimal('qty_difference', 16, 4)->default(0);
            $table->decimal('price_difference', 16, 4)->default(0);
            $table->decimal('amount_difference', 16, 4)->default(0);
            $table->timestamp('matched_at')->nullable();
            $table->unsignedBigInteger('matched_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'matched_at']);
            $table->index('match_status');
            $table->index('purchase_order_id');
            $table->index('goods_receipt_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_match_results');
        Schema::dropIfExists('procurement_match_configs');
    }
};

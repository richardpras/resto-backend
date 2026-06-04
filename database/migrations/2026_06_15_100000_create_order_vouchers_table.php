<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('member_voucher_id')->constrained('member_vouchers')->restrictOnDelete();
            $table->foreignId('voucher_id')->constrained('loyalty_vouchers')->restrictOnDelete();
            $table->string('voucher_code', 64);
            $table->string('discount_type', 32);
            $table->decimal('discount_value', 12, 2);
            $table->decimal('discount_amount', 12, 2);
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->unique('order_id', 'order_vouchers_order_id_unique');
            $table->index('voucher_id', 'order_vouchers_voucher_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_vouchers');
    }
};

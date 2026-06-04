<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('voucher_id')->constrained('loyalty_vouchers')->restrictOnDelete();
            $table->string('voucher_code', 64);
            $table->string('status', 32)->default('issued');
            $table->timestamp('issued_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('voucher_code', 'member_vouchers_voucher_code_unique');
            $table->index('member_id', 'member_vouchers_member_idx');
            $table->index('voucher_id', 'member_vouchers_voucher_idx');
            $table->index('status', 'member_vouchers_status_idx');
            $table->index(['outlet_id', 'voucher_id', 'member_id'], 'member_vouchers_outlet_voucher_member_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_vouchers');
    }
};

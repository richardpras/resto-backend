<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('total_amount', 14, 2);
            $table->timestamp('transaction_at');
            $table->timestamps();

            $table->unique('order_id');
            $table->index(['member_id', 'transaction_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_transactions');
    }
};

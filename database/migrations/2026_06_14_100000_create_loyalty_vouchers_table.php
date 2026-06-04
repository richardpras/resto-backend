<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_vouchers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('voucher_type', 32)->default('manual');
            $table->string('value_type', 32);
            $table->decimal('value', 15, 2)->default(0);
            $table->decimal('minimum_spend', 15, 2)->default(0);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'loyalty_vouchers_outlet_code_unique');
            $table->index('outlet_id', 'loyalty_vouchers_outlet_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_vouchers');
    }
};

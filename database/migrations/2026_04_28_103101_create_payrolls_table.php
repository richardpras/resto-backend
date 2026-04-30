<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('base_amount', 14, 2)->default(0);
            $table->decimal('adjustment_amount', 14, 2)->default(0);
            $table->decimal('deduction_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->string('status')->default('posted');
            $table->unsignedBigInteger('journal_id')->nullable()->index();
            $table->json('adjustments')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};

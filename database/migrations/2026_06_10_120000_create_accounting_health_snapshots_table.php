<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->date('snapshot_date')->index();
            $table->unsignedInteger('posting_failures')->default(0);
            $table->decimal('gift_card_variance', 18, 2)->default(0);
            $table->decimal('inventory_variance', 18, 2)->default(0);
            $table->decimal('payroll_variance', 18, 2)->default(0);
            $table->decimal('procurement_variance', 18, 2)->default(0);
            $table->string('severity', 16)->default('healthy');
            $table->timestamps();

            $table->unique(['outlet_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_health_snapshots');
    }
};

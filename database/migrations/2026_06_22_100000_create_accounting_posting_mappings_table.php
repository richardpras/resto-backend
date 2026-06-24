<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_posting_mappings')) {
            return;
        }

        Schema::create('accounting_posting_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('module', 64);
            $table->string('rule_key', 128);
            $table->unsignedBigInteger('chart_account_id');
            $table->timestamps();

            $table->foreign('chart_account_id')->references('id')->on('accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->unique(['tenant_id', 'outlet_id', 'module', 'rule_key'], 'accounting_posting_mappings_scope_unique');
            $table->index(['module', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_posting_mappings');
    }
};

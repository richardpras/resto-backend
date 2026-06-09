<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_settings')) {
            Schema::create('accounting_settings', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->unsignedBigInteger('outlet_id')->nullable();
                $table->string('revenue_posting_mode', 32)->default('realtime');
                $table->timestamps();

                $table->unique(['tenant_id', 'outlet_id']);
            });
        }

        if (! Schema::hasTable('accounting_posting_failures')) {
            Schema::create('accounting_posting_failures', function (Blueprint $table): void {
                $table->id();
                $table->string('source_type', 64);
                $table->unsignedBigInteger('source_id');
                $table->unsignedBigInteger('outlet_id')->nullable();
                $table->string('error_code', 64);
                $table->text('error_message');
                $table->json('payload_json')->nullable();
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('journal_id')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'source_type']);
                $table->index(['source_type', 'source_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_posting_failures');
        Schema::dropIfExists('accounting_settings');
    }
};

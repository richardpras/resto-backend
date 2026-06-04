<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_automation_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('loyalty_automations')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('trigger_type', 64);
            $table->string('action_type', 64);
            $table->string('status', 32);
            $table->json('result_json')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['automation_id', 'member_id', 'executed_at'], 'loyalty_automation_logs_lookup_idx');
            $table->index(['automation_id', 'status'], 'loyalty_automation_logs_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_automation_logs');
    }
};

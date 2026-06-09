<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->string('rule_name', 120);
            $table->string('rule_type', 60);
            $table->decimal('threshold_value', 14, 4)->default(0);
            $table->string('severity', 20)->default('warning');
            $table->json('notification_channels')->nullable();
            $table->boolean('escalation_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['outlet_id', 'rule_type', 'is_active']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });

        Schema::create('automation_alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('automation_rule_id')->nullable();
            $table->string('alert_type', 60);
            $table->string('severity', 20);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->json('payload_json')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status', 'severity']);
            $table->index(['outlet_id', 'alert_type', 'status']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('automation_rule_id')->references('id')->on('automation_rules')->nullOnDelete();
        });

        Schema::create('automation_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedInteger('alerts_generated')->default(0);
            $table->unsignedInteger('critical_alerts')->default(0);
            $table->unsignedInteger('warnings')->default(0);
            $table->unsignedInteger('recommendations_generated')->default(0);
            $table->unsignedInteger('resolved_alerts')->default(0);
            $table->timestamps();

            $table->unique(['snapshot_date', 'outlet_id'], 'auto_snapshots_date_outlet_unique');
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });

        Schema::create('automation_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('automation_alert_id');
            $table->string('channel', 30);
            $table->string('status', 20)->default('pending');
            $table->json('payload_json')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'channel', 'status']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('automation_alert_id')->references('id')->on('automation_alerts')->cascadeOnDelete();
        });

        Schema::create('automation_escalation_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('severity', 20)->default('critical');
            $table->unsignedSmallInteger('day_offset')->default(0);
            $table->string('notify_role', 60);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['outlet_id', 'severity', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_escalation_rules');
        Schema::dropIfExists('automation_notifications');
        Schema::dropIfExists('automation_snapshots');
        Schema::dropIfExists('automation_alerts');
        Schema::dropIfExists('automation_rules');
    }
};

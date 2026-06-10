<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_health_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->string('provider', 32)->index();
            $table->date('snapshot_date')->index();
            $table->string('health_status', 16)->default('healthy');
            $table->decimal('payment_success_rate', 8, 2)->default(0);
            $table->decimal('webhook_success_rate', 8, 2)->default(0);
            $table->unsignedInteger('stale_payments')->default(0);
            $table->unsignedInteger('failed_webhooks')->default(0);
            $table->unsignedInteger('average_processing_time_ms')->default(0);
            $table->unsignedInteger('active_incidents')->default(0);
            $table->timestamps();

            $table->unique(['outlet_id', 'provider', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_health_snapshots');
    }
};

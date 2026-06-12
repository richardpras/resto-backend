<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->string('shift_close_open_bill_policy', 16)->default('warn')->after('allow_negative_stock');
        });

        Schema::create('shift_close_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->default(1);
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('pos_session_id')->nullable();
            $table->unsignedBigInteger('run_by_user_id')->nullable();
            $table->string('status', 32)->default('started');
            $table->string('severity', 16)->nullable();
            $table->boolean('ready')->default(false);
            $table->json('preflight_snapshot')->nullable();
            $table->json('result_snapshot')->nullable();
            $table->decimal('cash_expected', 16, 2)->nullable();
            $table->decimal('cash_actual', 16, 2)->nullable();
            $table->decimal('cash_variance', 16, 2)->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status']);
            $table->index(['outlet_id', 'completed_at']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_close_runs');

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('shift_close_open_bill_policy');
        });
    }
};

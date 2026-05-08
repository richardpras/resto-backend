<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('printer_profile_id')->nullable()->after('printer_id')->index();
            $table->unsignedBigInteger('printer_route_id')->nullable()->after('printer_profile_id')->index();
            $table->string('idempotency_key', 190)->nullable()->after('source_id')->index();
            $table->string('dedupe_key', 190)->nullable()->after('idempotency_key');
            $table->json('printable_snapshot')->nullable()->after('content');
            $table->json('route_snapshot')->nullable()->after('printable_snapshot');
            $table->timestamp('queued_at')->nullable()->after('status');
            $table->timestamp('locked_at')->nullable()->after('queued_at');
            $table->string('locked_by', 120)->nullable()->after('locked_at');
            $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            $table->timestamp('next_retry_at')->nullable()->after('last_attempt_at')->index();
            $table->unsignedTinyInteger('max_attempts')->default(5)->after('next_retry_at');
            $table->boolean('retryable')->default(true)->after('max_attempts');
            $table->timestamp('failed_at')->nullable()->after('retryable');
            $table->string('recovery_state', 32)->default('none')->after('failed_at')->index();
            $table->unsignedBigInteger('recovered_from_job_id')->nullable()->after('recovery_state')->index();
            $table->json('failure_context')->nullable()->after('last_error');
            $table->timestamp('processed_at')->nullable()->after('failure_context');

            $table->index(['outlet_id', 'status', 'next_retry_at'], 'print_jobs_outlet_status_retry_idx');
            $table->unique(['outlet_id', 'dedupe_key'], 'print_jobs_outlet_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropUnique('print_jobs_outlet_dedupe_unique');
            $table->dropIndex('print_jobs_outlet_status_retry_idx');
            $table->dropColumn([
                'printer_profile_id',
                'printer_route_id',
                'idempotency_key',
                'dedupe_key',
                'printable_snapshot',
                'route_snapshot',
                'queued_at',
                'locked_at',
                'locked_by',
                'last_attempt_at',
                'next_retry_at',
                'max_attempts',
                'retryable',
                'failed_at',
                'recovery_state',
                'recovered_from_job_id',
                'failure_context',
                'processed_at',
            ]);
        });
    }
};

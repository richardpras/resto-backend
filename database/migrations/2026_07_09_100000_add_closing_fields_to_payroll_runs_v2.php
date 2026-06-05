<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs_v2', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('finalized_at');
            $table->timestamp('closed_at')->nullable()->after('paid_at');
            $table->string('payment_status', 20)->default('pending')->after('status');
            $table->foreignId('closed_by')->nullable()->after('finalized_by')->constrained('users')->nullOnDelete();
            $table->text('closed_notes')->nullable()->after('closed_by');

            $table->index('payment_status', 'payroll_runs_v2_payment_status_idx');
            $table->index('closed_at', 'payroll_runs_v2_closed_at_idx');
        });

        Schema::create('payroll_run_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs_v2')->cascadeOnDelete();
            $table->string('action', 30);
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['payroll_run_id', 'created_at'], 'payroll_run_audits_run_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_run_audits');

        Schema::table('payroll_runs_v2', function (Blueprint $table) {
            $table->dropIndex('payroll_runs_v2_payment_status_idx');
            $table->dropIndex('payroll_runs_v2_closed_at_idx');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['paid_at', 'closed_at', 'payment_status', 'closed_notes']);
        });
    }
};

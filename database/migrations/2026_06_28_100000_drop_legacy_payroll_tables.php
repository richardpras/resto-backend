<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('attendance_audit_logs');
        Schema::dropIfExists('attendance_sync_logs');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('overtimes');
        Schema::dropIfExists('adjustments');
        Schema::dropIfExists('loans');
    }

    public function down(): void
    {
        // Legacy payroll tables are intentionally not recreated.
    }
};

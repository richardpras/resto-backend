<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('deduct_leave_balance')->default(true);
            $table->boolean('paid_leave')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'leave_types_outlet_code_uniq');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('total_days');
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status'], 'leave_requests_outlet_status_idx');
            $table->index(['employee_id', 'start_date', 'end_date'], 'leave_requests_emp_dates_idx');
        });

        Schema::create('employee_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->decimal('allocated_days', 8, 2)->default(0);
            $table->decimal('used_days', 8, 2)->default(0);
            $table->decimal('remaining_days', 8, 2)->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id'], 'emp_leave_bal_emp_type_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_leave_balances');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_types');
    }
};

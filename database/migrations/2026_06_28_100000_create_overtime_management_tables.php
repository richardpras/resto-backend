<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overtime_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->decimal('multiplier', 5, 2)->default(1.0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'ot_types_outlet_code_uniq');
        });

        Schema::create('overtime_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('overtime_type_id')->constrained('overtime_types')->cascadeOnDelete();
            $table->date('overtime_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedInteger('total_minutes');
            $table->decimal('total_hours', 8, 2);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status'], 'ot_requests_outlet_status_idx');
            $table->index(['employee_id', 'overtime_date'], 'ot_requests_emp_date_idx');
        });

        Schema::create('overtime_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('overtime_date');
            $table->unsignedInteger('approved_minutes')->default(0);
            $table->decimal('approved_hours', 8, 2)->default(0);
            $table->unsignedSmallInteger('request_count')->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'overtime_date'], 'ot_daily_emp_date_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_daily_summaries');
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('overtime_types');
    }
};

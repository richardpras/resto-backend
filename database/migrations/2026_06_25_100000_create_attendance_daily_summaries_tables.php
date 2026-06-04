<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->foreignId('scheduled_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->time('scheduled_start')->nullable();
            $table->time('scheduled_end')->nullable();
            $table->dateTime('clock_in')->nullable();
            $table->dateTime('clock_out')->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('early_leave_minutes')->default(0);
            $table->boolean('is_absent')->default(false);
            $table->boolean('is_incomplete')->default(false);
            $table->boolean('requires_review')->default(false);
            $table->string('attendance_status', 30);
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['outlet_id', 'attendance_date']);
            $table->index(['employee_id', 'attendance_date']);
            $table->index(['attendance_status', 'attendance_date'], 'att_summaries_status_date_idx');
        });

        Schema::create('attendance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_summary_id')->constrained('attendance_daily_summaries')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_type', 30);
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_reviews');
        Schema::dropIfExists('attendance_daily_summaries');
    }
};

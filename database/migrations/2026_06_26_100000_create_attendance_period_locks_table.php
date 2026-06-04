<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'period_start', 'period_end'], 'att_period_locks_outlet_range_uniq');
            $table->index(['outlet_id', 'status'], 'att_period_locks_outlet_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_period_locks');
    }
};

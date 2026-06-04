<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_rosters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->date('roster_date');
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'roster_date']);
            $table->index(['outlet_id', 'roster_date']);
            $table->index(['employee_id', 'roster_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_rosters');
    }
};

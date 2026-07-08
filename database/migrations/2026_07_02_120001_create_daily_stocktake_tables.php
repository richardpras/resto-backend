<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stocktake_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->date('business_date');
            $table->string('status', 32)->default('draft');
            $table->timestamp('opening_submitted_at')->nullable();
            $table->timestamp('closing_submitted_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'business_date']);
            $table->index(['outlet_id', 'status']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('posted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('daily_stocktake_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('session_id')->constrained('daily_stocktake_sessions')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->cascadeOnDelete();
            $table->decimal('previous_closing_qty', 14, 4)->default(0);
            $table->decimal('opening_qty', 14, 4)->nullable();
            $table->decimal('closing_qty', 14, 4)->nullable();
            $table->decimal('purchases_qty', 14, 4)->default(0);
            $table->decimal('theoretical_usage_qty', 14, 4)->default(0);
            $table->decimal('overnight_variance_qty', 14, 4)->default(0);
            $table->decimal('operational_variance_qty', 14, 4)->default(0);
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->timestamps();

            $table->unique(['session_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stocktake_lines');
        Schema::dropIfExists('daily_stocktake_sessions');
    }
};

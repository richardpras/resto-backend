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
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'business_date'], 'daily_stocktake_outlet_date_unique');
            $table->index(['outlet_id', 'status']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });

        Schema::create('daily_stocktake_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('previous_closing_qty', 16, 4)->default(0);
            $table->decimal('opening_qty', 16, 4)->nullable();
            $table->decimal('closing_qty', 16, 4)->nullable();
            $table->decimal('purchases_qty', 16, 4)->default(0);
            $table->decimal('theoretical_usage_qty', 16, 4)->default(0);
            $table->decimal('overnight_variance_qty', 16, 4)->default(0);
            $table->decimal('operational_variance_qty', 16, 4)->default(0);
            $table->decimal('unit_cost', 16, 4)->default(0);
            $table->timestamps();

            $table->unique(['session_id', 'ingredient_id'], 'daily_stocktake_line_unique');
            $table->foreign('session_id')->references('id')->on('daily_stocktake_sessions')->cascadeOnDelete();
            $table->foreign('ingredient_id')->references('id')->on('ingredients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stocktake_lines');
        Schema::dropIfExists('daily_stocktake_sessions');
    }
};

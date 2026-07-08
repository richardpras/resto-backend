<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlet_reservation_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->unique();
            $table->boolean('public_enabled')->default(false);
            $table->string('public_slug', 80)->unique();
            $table->enum('deposit_mode', ['percent', 'flat'])->default('flat');
            $table->decimal('deposit_percent', 5, 2)->nullable();
            $table->decimal('deposit_flat_amount', 14, 2)->nullable();
            $table->boolean('preorder_required')->default(false);
            $table->text('deposit_instructions')->nullable();
            $table->unsignedInteger('deposit_review_timeout_hours')->nullable();
            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_reservation_settings');
    }
};

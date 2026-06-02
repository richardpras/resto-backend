<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('reservation_code', 64)->unique();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 40)->nullable();
            $table->unsignedInteger('party_size');
            $table->dateTime('reservation_at');
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('seated_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('no_show_at')->nullable();
            $table->enum('status', ['draft', 'confirmed', 'checked_in', 'seated', 'completed', 'cancelled', 'no_show'])
                ->default('draft');
            $table->timestamps();

            $table->index(['outlet_id', 'reservation_at']);
            $table->index(['outlet_id', 'status']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('table_id')->references('id')->on('tables')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};

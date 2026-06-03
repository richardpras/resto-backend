<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_table_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('table_id');
            $table->dateTime('allocated_at');
            $table->unsignedBigInteger('allocated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['reservation_id', 'table_id']);
            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();
            $table->foreign('table_id')->references('id')->on('tables')->cascadeOnDelete();
            $table->foreign('allocated_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_table_allocations');
    }
};

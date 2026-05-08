<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_job_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('print_job_id')->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->string('event_type', 64)->index();
            $table->string('status', 32)->nullable()->index();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('print_job_id')->references('id')->on('print_jobs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_job_events');
    }
};

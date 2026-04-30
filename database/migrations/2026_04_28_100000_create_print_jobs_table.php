<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->enum('type', ['receipt', 'kitchen']);
            $table->string('printer_id')->nullable();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->json('content');
            $table->enum('status', ['pending', 'done', 'failed'])->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};

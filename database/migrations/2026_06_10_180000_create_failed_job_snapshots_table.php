<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_job_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->unsignedInteger('total_failures')->default(0);
            $table->unsignedInteger('critical_failures')->default(0);
            $table->unsignedInteger('resolved_failures')->default(0);
            $table->string('health_status', 20)->default('healthy');
            $table->timestamps();

            $table->unique('snapshot_date');
            $table->index('health_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_job_snapshots');
    }
};

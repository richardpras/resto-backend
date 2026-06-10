<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_incidents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->string('provider', 32)->index();
            $table->string('incident_type', 64)->index();
            $table->string('severity', 16);
            $table->string('title');
            $table->text('description');
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('status', 16)->default('open')->index();
            $table->timestamps();

            $table->index(['outlet_id', 'provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_incidents');
    }
};

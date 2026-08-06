<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_session_cash_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->unsignedBigInteger('pos_session_id')->index();
            $table->string('direction', 8); // in | out
            $table->decimal('amount', 15, 2);
            $table->string('category', 64);
            $table->string('notes', 500)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->string('client_local_ref', 120)->nullable()->unique();
            $table->unsignedBigInteger('journal_id')->nullable()->index();
            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('pos_session_id')->references('id')->on('pos_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_session_cash_movements');
    }
};

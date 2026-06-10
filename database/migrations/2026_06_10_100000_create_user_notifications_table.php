<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('user_id');
            $table->string('severity', 20);
            $table->string('source_module', 40);
            $table->string('source_type', 80);
            $table->string('source_id', 80);
            $table->string('title', 255);
            $table->text('message');
            $table->string('action_url', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('outlet_id');
            $table->index('user_id');
            $table->index('read_at');
            $table->index('source_module');
            $table->index('severity');
            $table->index('created_at');
            $table->unique(
                ['user_id', 'outlet_id', 'source_module', 'source_type', 'source_id'],
                'user_notifications_dedupe_unique',
            );
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};

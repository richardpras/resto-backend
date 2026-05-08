<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminal_sync_conflict_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('terminal_device_id')->nullable()->constrained('terminal_devices')->nullOnDelete();
            $table->foreignId('terminal_sync_operation_id')->constrained('terminal_sync_operations')->cascadeOnDelete();
            $table->string('conflict_type', 64);
            $table->string('recommendation', 255)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['outlet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terminal_sync_conflict_events');
    }
};

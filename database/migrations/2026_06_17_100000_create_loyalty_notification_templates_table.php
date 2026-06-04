<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('event_type', 64);
            $table->string('channel', 32);
            $table->string('subject')->nullable();
            $table->text('content');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'loyalty_notification_templates_outlet_code_unique');
            $table->index(['outlet_id', 'event_type', 'channel', 'is_active'], 'loyalty_notification_templates_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_notification_templates');
    }
};

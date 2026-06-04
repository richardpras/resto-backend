<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('segment_type', 64);
            $table->json('config_json');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'member_segments_outlet_code_unique');
            $table->index(['outlet_id', 'segment_type', 'is_active'], 'member_segments_outlet_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_segments');
    }
};

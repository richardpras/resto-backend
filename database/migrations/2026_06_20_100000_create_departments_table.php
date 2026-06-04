<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'code'], 'departments_outlet_code_unique');
            $table->index(['outlet_id', 'is_active'], 'departments_outlet_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};

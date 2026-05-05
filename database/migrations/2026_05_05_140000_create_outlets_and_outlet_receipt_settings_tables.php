<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('name', 255);
            $table->timestamps();
        });

        Schema::create('outlet_receipt_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('outlet_id', 64);
            $table->text('receipt_header')->nullable();
            $table->text('receipt_footer')->nullable();
            $table->boolean('show_logo')->default(false);
            $table->boolean('show_tax_breakdown')->default(false);
            $table->timestamps();

            $table->unique('outlet_id');
            $table->foreign('outlet_id')
                ->references('id')
                ->on('outlets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_receipt_settings');
        Schema::dropIfExists('outlets');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_postings', function (Blueprint $table): void {
            $table->id();
            $table->string('posting_no')->unique();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->string('status', 16)->default('draft');
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['outlet_id', 'status']);
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_postings');
    }
};

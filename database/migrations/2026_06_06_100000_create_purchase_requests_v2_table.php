<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests_v2', function (Blueprint $table): void {
            $table->id();
            $table->string('request_no')->unique();
            $table->unsignedBigInteger('outlet_id');
            $table->string('requested_by');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'converted', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index('outlet_id');
            $table->index('status');
        });

        Schema::create('purchase_request_items_v2', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests_v2')->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('quantity', 14, 2);
            $table->string('unit', 20)->nullable();
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items_v2');
        Schema::dropIfExists('purchase_requests_v2');
    }
};

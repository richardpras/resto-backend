<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->foreignId('purchase_request_id')->constrained('purchase_requests')->restrictOnDelete();
            $table->string('number')->unique();
            $table->enum('status', ['open', 'partially_received', 'fully_received'])->default('open');
            $table->date('order_date');
            $table->string('supplier_name')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained('ingredients')->restrictOnDelete();
            $table->decimal('ordered_qty', 14, 2);
            $table->decimal('received_qty', 14, 2)->default(0);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['purchase_order_id', 'ingredient_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};

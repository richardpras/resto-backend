<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'recovery_status')) {
                $table->string('recovery_status', 40)->nullable()->after('notes')->index();
            }
            if (! Schema::hasColumn('order_items', 'recovery_reason')) {
                $table->text('recovery_reason')->nullable()->after('recovery_status');
            }
            if (! Schema::hasColumn('order_items', 'recovery_approved_by_user_id')) {
                $table->unsignedBigInteger('recovery_approved_by_user_id')->nullable()->after('recovery_reason');
            }
            if (! Schema::hasColumn('order_items', 'recovery_approved_at')) {
                $table->timestamp('recovery_approved_at')->nullable()->after('recovery_approved_by_user_id');
            }
            if (! Schema::hasColumn('order_items', 'replaced_by_order_item_id')) {
                $table->unsignedBigInteger('replaced_by_order_item_id')->nullable()->after('recovery_approved_at');
            }
        });

        Schema::create('order_item_recovery_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->index();
            $table->unsignedBigInteger('order_item_id')->index();
            $table->string('event_code', 80);
            $table->string('recovery_status', 40)->nullable();
            $table->text('reason')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->timestamps();

            $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_recovery_events');

        Schema::table('order_items', function (Blueprint $table): void {
            foreach (['replaced_by_order_item_id', 'recovery_approved_at', 'recovery_approved_by_user_id', 'recovery_reason', 'recovery_status'] as $col) {
                if (Schema::hasColumn('order_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

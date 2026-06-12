<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->timestamp('opened_in_pos_at')->nullable()->after('reviewed_by_user_id');
            $table->foreignId('opened_in_pos_by_user_id')->nullable()->after('opened_in_pos_at')->constrained('users')->nullOnDelete();
            $table->json('original_items_snapshot')->nullable()->after('opened_in_pos_by_user_id');
        });

        Schema::table('pos_sessions', function (Blueprint $table): void {
            $table->string('session_type', 32)->default('standard')->after('status');
            $table->string('source_order_code', 64)->nullable()->after('session_type');
        });
    }

    public function down(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('opened_in_pos_by_user_id');
            $table->dropColumn(['opened_in_pos_at', 'original_items_snapshot']);
        });

        Schema::table('pos_sessions', function (Blueprint $table): void {
            $table->dropColumn(['session_type', 'source_order_code']);
        });
    }
};

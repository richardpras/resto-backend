<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->timestamp('reviewed_at')->nullable()->after('cashier_call_count');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->json('review_draft')->nullable()->after('reviewed_by_user_id');
            $table->json('adjustment_log')->nullable()->after('review_draft');
        });
    }

    public function down(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['reviewed_at', 'review_draft', 'adjustment_log']);
        });
    }
};

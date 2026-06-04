<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_campaigns', function (Blueprint $table): void {
            $table->timestamp('activated_at')->nullable()->after('status');
            $table->timestamp('completed_at')->nullable()->after('activated_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['activated_at', 'completed_at', 'cancelled_at']);
        });
    }
};

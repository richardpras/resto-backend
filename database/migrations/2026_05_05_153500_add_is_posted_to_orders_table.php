<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'is_posted')) {
                $table->boolean('is_posted')->default(false)->after('stock_deducted_at');
                $table->index('is_posted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'is_posted')) {
                $table->dropIndex(['is_posted']);
                $table->dropColumn('is_posted');
            }
        });
    }
};

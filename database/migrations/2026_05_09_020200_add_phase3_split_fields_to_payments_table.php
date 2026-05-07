<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('order_split_id')
                ->nullable()
                ->after('order_id')
                ->constrained('order_splits')
                ->nullOnDelete();
            $table->string('status', 32)->default('paid')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('order_split_id');
            $table->dropColumn('status');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('source_type', 32)->nullable()->after('source');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->string('source_code', 64)->nullable()->after('source_id');

            $table->index(['source_type', 'source_id'], 'orders_source_type_id_index');
            $table->index('source_code', 'orders_source_code_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_source_type_id_index');
            $table->dropIndex('orders_source_code_index');
            $table->dropColumn(['source_type', 'source_id', 'source_code']);
        });
    }
};

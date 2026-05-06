<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'table_id')) {
                $table->foreignId('table_id')
                    ->nullable()
                    ->after('customer_phone')
                    ->constrained('tables')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('orders', 'table_name')) {
                $table->string('table_name', 191)->nullable()->after('table_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'table_name')) {
                $table->dropColumn('table_name');
            }

            if (Schema::hasColumn('orders', 'table_id')) {
                $table->dropForeign(['table_id']);
                $table->dropColumn('table_id');
            }
        });
    }
};

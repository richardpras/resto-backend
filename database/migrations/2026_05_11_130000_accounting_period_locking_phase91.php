<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounting_periods', 'name')) {
                $table->string('name', 100)->nullable()->after('outlet_id');
            }
            if (! Schema::hasColumn('accounting_periods', 'closed_by_user_id')) {
                $table->unsignedBigInteger('closed_by_user_id')->nullable()->after('closed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounting_periods', function (Blueprint $table): void {
            foreach (['closed_by_user_id', 'name'] as $column) {
                if (Schema::hasColumn('accounting_periods', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

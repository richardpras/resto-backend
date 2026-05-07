<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            if (! Schema::hasColumn('journals', 'reversal_journal_id')) {
                $table->unsignedBigInteger('reversal_journal_id')->nullable()->after('reversal_of_journal_id')->index();
            }
            if (! Schema::hasColumn('journals', 'reversed_journal_id')) {
                $table->unsignedBigInteger('reversed_journal_id')->nullable()->after('reversal_journal_id')->index();
            }
            if (! Schema::hasColumn('journals', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('posted_at');
            }
            if (! Schema::hasColumn('journals', 'reversed_by_user_id')) {
                $table->unsignedBigInteger('reversed_by_user_id')->nullable()->after('reversed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('journals', function (Blueprint $table): void {
            foreach (['reversal_journal_id', 'reversed_journal_id', 'reversed_at', 'reversed_by_user_id'] as $column) {
                if (Schema::hasColumn('journals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            if (! Schema::hasColumn('members', 'loyalty_account_id')) {
                $table->foreignId('loyalty_account_id')
                    ->nullable()
                    ->after('outlet_id')
                    ->constrained('loyalty_accounts')
                    ->nullOnDelete();
                $table->unique('loyalty_account_id', 'members_loyalty_account_id_unique');
            }
        });

        Schema::table('members', function (Blueprint $table): void {
            if (Schema::hasColumn('members', 'outlet_id') && Schema::hasColumn('members', 'phone')) {
                $table->index(['outlet_id', 'phone'], 'members_outlet_phone_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            if (Schema::hasColumn('members', 'loyalty_account_id')) {
                $table->dropUnique('members_loyalty_account_id_unique');
                $table->dropConstrainedForeignId('loyalty_account_id');
            }
            if (Schema::hasIndex('members', 'members_outlet_phone_idx')) {
                $table->dropIndex('members_outlet_phone_idx');
            }
        });
    }
};

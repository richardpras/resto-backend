<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->string('import_code', 80)->nullable()->after('outlet_id');
            $table->unique(['outlet_id', 'import_code'], 'loyalty_accounts_outlet_import_code_unique');
        });

        Schema::table('members', function (Blueprint $table): void {
            $table->string('import_code', 80)->nullable()->after('outlet_id');
            $table->unique(['outlet_id', 'import_code'], 'members_outlet_import_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropUnique('members_outlet_import_code_unique');
            $table->dropColumn('import_code');
        });

        Schema::table('loyalty_accounts', function (Blueprint $table): void {
            $table->dropUnique('loyalty_accounts_outlet_import_code_unique');
            $table->dropColumn('import_code');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table): void {
            if (! Schema::hasColumn('tables', 'code')) {
                $table->string('code', 64)->nullable()->after('outlet_id');
            }
            if (! Schema::hasColumn('tables', 'zone')) {
                $table->string('zone', 120)->nullable()->after('capacity');
            }
            if (! Schema::hasColumn('tables', 'active')) {
                $table->boolean('active')->default(true)->after('status');
            }
        });

        Schema::table('tables', function (Blueprint $table): void {
            $table->unique(['outlet_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table): void {
            $table->dropUnique('tables_outlet_id_code_unique');
            if (Schema::hasColumn('tables', 'active')) {
                $table->dropColumn('active');
            }
            if (Schema::hasColumn('tables', 'zone')) {
                $table->dropColumn('zone');
            }
            if (Schema::hasColumn('tables', 'code')) {
                $table->dropColumn('code');
            }
        });
    }
};

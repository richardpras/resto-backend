<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('subtype')->nullable()->after('type');
            $table->text('description')->nullable()->after('parent_id');
        });

        Schema::table('journals', function (Blueprint $table): void {
            $table->string('outlet')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['subtype', 'description']);
        });

        Schema::table('journals', function (Blueprint $table): void {
            $table->dropColumn('outlet');
        });
    }
};

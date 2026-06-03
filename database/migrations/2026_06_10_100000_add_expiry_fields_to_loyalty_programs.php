<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table): void {
            $table->boolean('expiry_enabled')->default(false)->after('is_active');
            $table->unsignedInteger('expiry_days')->nullable()->after('expiry_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table): void {
            $table->dropColumn(['expiry_enabled', 'expiry_days']);
        });
    }
};

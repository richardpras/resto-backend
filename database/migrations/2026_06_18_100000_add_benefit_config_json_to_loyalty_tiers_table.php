<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_tiers', function (Blueprint $table): void {
            $table->json('benefit_config_json')->nullable()->after('qualification_config');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_tiers', function (Blueprint $table): void {
            $table->dropColumn('benefit_config_json');
        });
    }
};

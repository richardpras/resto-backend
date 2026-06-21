<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->string('logo_path', 512)->nullable()->after('logo');
            $table->string('logo_path_fallback', 512)->nullable()->after('logo_path');
            $table->string('logo_thermal_path', 512)->nullable()->after('logo_path_fallback');
            $table->unsignedInteger('logo_version')->default(0)->after('logo_thermal_path');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn([
                'logo_path',
                'logo_path_fallback',
                'logo_thermal_path',
                'logo_version',
            ]);
        });
    }
};

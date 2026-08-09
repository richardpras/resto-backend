<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setting_printers', function (Blueprint $table) {
            $table->boolean('auto_cut')->default(true)->after('thermal_paper_width');
        });
    }

    public function down(): void
    {
        Schema::table('setting_printers', function (Blueprint $table) {
            $table->dropColumn('auto_cut');
        });
    }
};

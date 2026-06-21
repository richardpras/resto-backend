<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setting_printers', function (Blueprint $table): void {
            $table->string('thermal_paper_width', 8)->default('58mm')->after('connection');
        });

        DB::table('setting_printers')->update(['thermal_paper_width' => '58mm']);
    }

    public function down(): void
    {
        Schema::table('setting_printers', function (Blueprint $table): void {
            $table->dropColumn('thermal_paper_width');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->unsignedBigInteger('hardware_command_log_id')->nullable()->after('receipt_render_history_id')->index();
        });

        Schema::table('setting_printers', function (Blueprint $table): void {
            $table->unsignedBigInteger('printer_profile_id')->nullable()->after('assigned_categories')->index();
        });
    }

    public function down(): void
    {
        Schema::table('setting_printers', function (Blueprint $table): void {
            $table->dropColumn('printer_profile_id');
        });

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropColumn('hardware_command_log_id');
        });
    }
};

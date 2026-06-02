<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tables', function (Blueprint $table): void {
            $table->string('qr_public_id', 64)->nullable()->after('code');
            $table->boolean('qr_enabled')->default(false)->after('active');
            $table->unsignedInteger('qr_version')->default(1)->after('qr_enabled');
            $table->timestamp('qr_last_rotated_at')->nullable()->after('qr_version');
            $table->unique(['outlet_id', 'qr_public_id'], 'tables_outlet_qr_public_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tables', function (Blueprint $table): void {
            $table->dropUnique('tables_outlet_qr_public_unique');
            $table->dropColumn([
                'qr_public_id',
                'qr_enabled',
                'qr_version',
                'qr_last_rotated_at',
            ]);
        });
    }
};

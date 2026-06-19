<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('emoji');
            $table->string('image_path_fallback')->nullable()->after('image_path');
            $table->unsignedInteger('image_version')->default(0)->after('image_path_fallback');
            $table->unsignedSmallInteger('image_width')->nullable()->after('image_version');
            $table->unsignedSmallInteger('image_height')->nullable()->after('image_width');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropColumn([
                'image_path',
                'image_path_fallback',
                'image_version',
                'image_width',
                'image_height',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_categories', function (Blueprint $table): void {
            $table->string('name_en', 120)->nullable()->after('name');
            $table->string('name_id', 120)->nullable()->after('name_en');
            $table->string('description_en', 255)->nullable()->after('description');
            $table->string('description_id', 255)->nullable()->after('description_en');
        });

        DB::table('menu_categories')->update([
            'name_en' => DB::raw('name'),
            'name_id' => DB::raw('name'),
            'description_en' => DB::raw('description'),
            'description_id' => DB::raw('description'),
        ]);

        Schema::create('menu_category_printer_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('outlet_id')->index();
            $table->unsignedBigInteger('menu_category_id')->index();
            $table->unsignedBigInteger('printer_profile_id')->index();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('menu_category_id')->references('id')->on('menu_categories')->cascadeOnDelete();
            $table->foreign('printer_profile_id')->references('id')->on('printer_profiles')->cascadeOnDelete();
            $table->unique(['outlet_id', 'menu_category_id'], 'menu_cat_printer_map_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_category_printer_mappings');

        Schema::table('menu_categories', function (Blueprint $table): void {
            $table->dropColumn([
                'name_en',
                'name_id',
                'description_en',
                'description_id',
            ]);
        });
    }
};

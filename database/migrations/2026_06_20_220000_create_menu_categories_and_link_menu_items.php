<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('code', 80);
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('menu_category_id')->nullable()->after('category')->index();
            $table->foreign('menu_category_id')->references('id')->on('menu_categories')->nullOnDelete();
        });

        $rows = DB::table('menu_items')
            ->select('id', 'tenant_id', 'category')
            ->get();

        $categoryByTenantAndName = [];
        foreach ($rows as $row) {
            $tenantId = $row->tenant_id !== null ? (int) $row->tenant_id : null;
            $name = trim((string) ($row->category ?? ''));
            if ($name === '') {
                $name = 'Uncategorized';
            }
            $tenantKey = $tenantId !== null ? (string) $tenantId : 'null';
            $mapKey = $tenantKey.'|'.$name;
            if (isset($categoryByTenantAndName[$mapKey])) {
                continue;
            }

            $baseCode = Str::slug(Str::lower($name), '_');
            if ($baseCode === '') {
                $baseCode = 'uncategorized';
            }
            $code = $baseCode;
            $suffix = 1;
            while (
                DB::table('menu_categories')
                    ->where('tenant_id', $tenantId)
                    ->where('code', $code)
                    ->exists()
            ) {
                $suffix++;
                $code = substr($baseCode, 0, max(1, 75)).'_'.$suffix;
            }

            $id = DB::table('menu_categories')->insertGetId([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => $name,
                'description' => null,
                'sort_order' => 100,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $categoryByTenantAndName[$mapKey] = (int) $id;
        }

        foreach ($rows as $row) {
            $tenantId = $row->tenant_id !== null ? (int) $row->tenant_id : null;
            $name = trim((string) ($row->category ?? ''));
            if ($name === '') {
                $name = 'Uncategorized';
            }
            $tenantKey = $tenantId !== null ? (string) $tenantId : 'null';
            $mapKey = $tenantKey.'|'.$name;
            $categoryId = $categoryByTenantAndName[$mapKey] ?? null;
            if ($categoryId === null) {
                continue;
            }

            DB::table('menu_items')
                ->where('id', (int) $row->id)
                ->update([
                    'category' => $name,
                    'menu_category_id' => $categoryId,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropForeign(['menu_category_id']);
            $table->dropColumn('menu_category_id');
        });

        Schema::dropIfExists('menu_categories');
    }
};

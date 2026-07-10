<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->string('import_code', 80)->nullable()->after('outlet_id');
            $table->unique(['outlet_id', 'import_code'], 'ingredients_outlet_import_code_unique');
        });

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->string('import_code', 80)->nullable()->after('outlet_id');
            $table->unique(['outlet_id', 'import_code'], 'menu_items_outlet_import_code_unique');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('import_code', 80)->nullable()->after('id');
            $table->unique('import_code', 'suppliers_import_code_unique');
        });

        Schema::create('master_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('import_type', 64);
            $table->string('filename')->nullable();
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('summary_json')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'import_type']);
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_import_batches');

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique('suppliers_import_code_unique');
            $table->dropColumn('import_code');
        });

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropUnique('menu_items_outlet_import_code_unique');
            $table->dropColumn('import_code');
        });

        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropUnique('ingredients_outlet_import_code_unique');
            $table->dropColumn('import_code');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table): void {
            $table->date('effective_from')->nullable()->after('status');
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        Schema::create('outlet_tax_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->string('tax_id', 64);
            $table->timestamps();

            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes')->cascadeOnDelete();
            $table->unique(['outlet_id', 'tax_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('apply_tax')->default(false)->after('tax');
            $table->json('tax_snapshot')->nullable()->after('apply_tax');
        });

        // Backfill: existing orders with tax > 0 were taxed at checkout.
        DB::table('orders')->where('tax', '>', 0)->update(['apply_tax' => true]);

        if (Schema::hasTable('taxes') && Schema::hasTable('outlets')) {
            $taxId = 'tax-default';
            if (DB::table('taxes')->where('id', $taxId)->exists()) {
                $outletIds = DB::table('outlets')->pluck('id');
                foreach ($outletIds as $outletId) {
                    DB::table('outlet_tax_assignments')->insertOrIgnore([
                        'outlet_id' => $outletId,
                        'tax_id' => $taxId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['apply_tax', 'tax_snapshot']);
        });

        Schema::dropIfExists('outlet_tax_assignments');

        Schema::table('taxes', function (Blueprint $table): void {
            $table->dropColumn(['effective_from', 'effective_to']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_invoices', 'tax')) {
                $table->decimal('tax', 14, 2)->default(0)->after('total');
            }
            if (! Schema::hasColumn('purchase_invoices', 'status')) {
                $table->string('status', 16)->default('unpaid')->after('total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_invoices', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('purchase_invoices', 'tax')) {
                $table->dropColumn('tax');
            }
        });
    }
};

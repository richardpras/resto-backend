<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_requests', 'outlet')) {
                $table->string('outlet')->nullable()->after('outlet_id');
            }
            if (! Schema::hasColumn('purchase_requests', 'requested_by')) {
                $table->string('requested_by')->nullable()->after('outlet');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_orders', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('purchase_request_id')->constrained('suppliers')->restrictOnDelete();
            }
            if (! Schema::hasColumn('purchase_orders', 'notes')) {
                $table->text('notes')->nullable()->after('supplier_name');
            }
        });

        DB::statement("ALTER TABLE purchase_requests MODIFY status ENUM('draft','submitted','approved','rejected') NOT NULL DEFAULT 'draft'");
        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM('draft','sent','partial','completed') NOT NULL DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE purchase_orders MODIFY status ENUM('open','partially_received','fully_received') NOT NULL DEFAULT 'open'");
        DB::statement("ALTER TABLE purchase_requests MODIFY status ENUM('draft','approved','closed') NOT NULL DEFAULT 'approved'");

        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_orders', 'supplier_id')) {
                $table->dropConstrainedForeignId('supplier_id');
            }
            if (Schema::hasColumn('purchase_orders', 'notes')) {
                $table->dropColumn('notes');
            }
        });

        Schema::table('purchase_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_requests', 'requested_by')) {
                $table->dropColumn('requested_by');
            }
            if (Schema::hasColumn('purchase_requests', 'outlet')) {
                $table->dropColumn('outlet');
            }
        });
    }
};

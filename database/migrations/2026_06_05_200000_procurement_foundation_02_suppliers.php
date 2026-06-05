<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            if (! Schema::hasColumn('suppliers', 'payment_term_days')) {
                $table->unsignedInteger('payment_term_days')->nullable()->after('status');
            }
            if (! Schema::hasColumn('suppliers', 'lead_time_days')) {
                $table->unsignedInteger('lead_time_days')->nullable()->after('payment_term_days');
            }
            if (! Schema::hasColumn('suppliers', 'tax_number')) {
                $table->string('tax_number', 64)->nullable()->after('lead_time_days');
            }
            if (! Schema::hasColumn('suppliers', 'tax_name')) {
                $table->string('tax_name')->nullable()->after('tax_number');
            }
            if (! Schema::hasColumn('suppliers', 'tax_address')) {
                $table->text('tax_address')->nullable()->after('tax_name');
            }
            if (! Schema::hasColumn('suppliers', 'contact_person')) {
                $table->string('contact_person')->nullable()->after('tax_address');
            }
            if (! Schema::hasColumn('suppliers', 'contact_phone')) {
                $table->string('contact_phone', 64)->nullable()->after('contact_person');
            }
            if (! Schema::hasColumn('suppliers', 'contact_email')) {
                $table->string('contact_email')->nullable()->after('contact_phone');
            }
            if (! Schema::hasColumn('suppliers', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('contact_email');
            }
        });

        if (Schema::hasColumn('suppliers', 'is_active') && Schema::hasColumn('suppliers', 'status')) {
            DB::table('suppliers')->update([
                'is_active' => DB::raw("CASE WHEN status = 'active' THEN 1 ELSE 0 END"),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            foreach ([
                'is_active',
                'contact_email',
                'contact_phone',
                'contact_person',
                'tax_address',
                'tax_name',
                'tax_number',
                'lead_time_days',
                'payment_term_days',
            ] as $column) {
                if (Schema::hasColumn('suppliers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

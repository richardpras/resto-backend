<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_programs', function (Blueprint $table): void {
            if (! Schema::hasColumn('loyalty_programs', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            if (! Schema::hasColumn('loyalty_programs', 'effective_from')) {
                $table->date('effective_from')->nullable()->after('is_active');
            }
            if (! Schema::hasColumn('loyalty_programs', 'effective_until')) {
                $table->date('effective_until')->nullable()->after('effective_from');
            }
        });

        Schema::table('loyalty_program_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('loyalty_program_rules', 'rule_type')) {
                $table->string('rule_type', 32)->default('spend_based')->after('loyalty_program_id');
                $table->index(['loyalty_program_id', 'rule_type'], 'loyalty_program_rules_program_type_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_program_rules', function (Blueprint $table): void {
            if (Schema::hasColumn('loyalty_program_rules', 'rule_type')) {
                $table->dropIndex('loyalty_program_rules_program_type_idx');
                $table->dropColumn('rule_type');
            }
        });

        Schema::table('loyalty_programs', function (Blueprint $table): void {
            foreach (['description', 'effective_from', 'effective_until'] as $column) {
                if (Schema::hasColumn('loyalty_programs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

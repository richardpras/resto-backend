<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('type', 32);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['outlet_id', 'type', 'is_active'], 'loyalty_programs_scope_type_active_idx');
        });

        Schema::create('loyalty_program_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loyalty_program_id')->constrained('loyalty_programs')->cascadeOnDelete();
            $table->json('config');
            $table->timestamps();
        });

        Schema::create('loyalty_member_ledger', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('loyalty_program_id')->nullable()->constrained('loyalty_programs')->nullOnDelete();
            $table->string('type', 16);
            $table->string('reference_type', 64)->nullable();
            $table->string('reference_id', 64)->nullable();
            $table->integer('points');
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['member_id', 'created_at'], 'loyalty_member_ledger_member_created_idx');
            $table->unique(
                ['member_id', 'type', 'reference_type', 'reference_id'],
                'loyalty_member_ledger_earn_reference_unique',
            );
        });

        Schema::create('member_loyalty_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            $table->integer('current_points')->default(0);
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_loyalty_balances');
        Schema::dropIfExists('loyalty_member_ledger');
        Schema::dropIfExists('loyalty_program_rules');
        Schema::dropIfExists('loyalty_programs');
    }
};

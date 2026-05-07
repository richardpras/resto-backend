<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'category')) {
                $table->string('category', 100)->nullable()->after('type');
            }
            if (! Schema::hasColumn('accounts', 'scope')) {
                $table->string('scope', 20)->default('global')->after('tenant_id');
            }
            if (! Schema::hasColumn('accounts', 'outlet_id')) {
                $table->unsignedBigInteger('outlet_id')->nullable()->after('tenant_id')->index();
            }
            if (! Schema::hasColumn('accounts', 'config')) {
                $table->json('config')->nullable()->after('description');
            }
        });

        Schema::table('journals', function (Blueprint $table): void {
            if (! Schema::hasColumn('journals', 'outlet')) {
                $table->string('outlet')->nullable()->after('description');
            }
            if (! Schema::hasColumn('journals', 'outlet_id')) {
                $table->unsignedBigInteger('outlet_id')->nullable()->after('outlet')->index();
            }
            if (! Schema::hasColumn('journals', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('journals', 'posted_by')) {
                $table->unsignedBigInteger('posted_by')->nullable()->after('posted_at');
            }
            if (! Schema::hasColumn('journals', 'immutable')) {
                $table->boolean('immutable')->default(false)->after('posted_by');
            }
            if (! Schema::hasColumn('journals', 'reversal_of_journal_id')) {
                $table->unsignedBigInteger('reversal_of_journal_id')->nullable()->after('source_id')->index();
            }
        });

        Schema::table('journal_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('journal_entries', 'meta')) {
                $table->json('meta')->nullable()->after('memo');
            }
        });

        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('outlet_id')->nullable()->index();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'outlet_id', 'status']);
        });

        Schema::create('journal_posting_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->string('idempotency_key', 120);
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamp('processed_at');
            $table->timestamps();
            $table->unique(['scope', 'idempotency_key']);
            $table->index(['scope', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_posting_keys');
        Schema::dropIfExists('accounting_periods');

        Schema::table('journal_entries', function (Blueprint $table): void {
            if (Schema::hasColumn('journal_entries', 'meta')) {
                $table->dropColumn('meta');
            }
        });

        Schema::table('journals', function (Blueprint $table): void {
            foreach (['reversal_of_journal_id', 'immutable', 'posted_by', 'posted_at', 'outlet_id'] as $column) {
                if (Schema::hasColumn('journals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('accounts', function (Blueprint $table): void {
            foreach (['config', 'outlet_id', 'scope', 'category'] as $column) {
                if (Schema::hasColumn('accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

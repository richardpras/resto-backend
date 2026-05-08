<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('outlet_id')->default(0)->index()->comment('0 = global builtin');
            $table->string('kind', 64)->index();
            $table->string('code', 64)->default('default');
            $table->unsignedInteger('version')->default(1);
            $table->string('name', 160);
            $table->unsignedSmallInteger('thermal_width_chars')->default(42);
            $table->foreignId('printer_profile_id')->nullable()->constrained('printer_profiles')->nullOnDelete();
            $table->json('sections')->nullable();
            $table->json('defaults')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default_fallback')->default(false);
            $table->timestamps();

            $table->unique(['outlet_id', 'kind', 'code', 'version'], 'receipt_templates_scope_unique');
        });

        Schema::create('invoice_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('series_key', 32)->default('INV');
            $table->string('prefix', 32)->default('INV');
            $table->unsignedInteger('pad_length')->default(6);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();

            $table->unique(['outlet_id', 'series_key'], 'invoice_sequences_scope_unique');
        });

        Schema::create('fiscal_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->uuid('fiscal_uuid')->unique();
            $table->string('invoice_number', 96)->unique();
            $table->foreignId('invoice_sequence_id')->constrained('invoice_sequences')->restrictOnDelete();
            $table->unsignedBigInteger('sequence_value');
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->json('metadata')->nullable();
            $table->timestamp('issued_at')->useCurrent();
            $table->timestamps();

            $table->unique(['outlet_id', 'source_type', 'source_id'], 'fiscal_invoices_origin_unique');
            $table->index(['outlet_id', 'issued_at']);
        });

        Schema::create('receipt_render_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('receipt_template_id')->nullable()->constrained('receipt_templates')->nullOnDelete();
            $table->string('kind', 64);
            $table->string('render_fingerprint', 128);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('order_split_id')->nullable()->constrained('order_splits')->nullOnDelete();
            $table->json('context_snapshot');
            $table->longText('thermal_text');
            $table->longText('html_snapshot')->nullable();
            $table->string('pdf_storage_path', 255)->nullable();
            $table->foreignId('fiscal_invoice_id')->nullable()->constrained('fiscal_invoices')->nullOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            /** @see print_jobs.receipt_render_history_id — avoids circular FK between jobs and renders */
            $table->unsignedInteger('reprint_count')->default(0);
            $table->boolean('deferred_replay_pending')->default(false);
            $table->json('recovery_meta')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'render_fingerprint'], 'receipt_render_histories_fingerprint_unique');
            $table->index(['outlet_id', 'source_type', 'source_id']);
        });

        Schema::create('print_reprint_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('print_job_id')->nullable()->constrained('print_jobs')->nullOnDelete();
            $table->foreignId('receipt_render_history_id')->constrained('receipt_render_histories')->cascadeOnDelete();
            $table->string('action', 48);
            $table->string('reason', 512)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->foreignId('receipt_render_history_id')
                ->nullable()
                ->after('recovered_from_job_id')
                ->constrained('receipt_render_histories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table): void {
            $table->dropForeign(['receipt_render_history_id']);
            $table->dropColumn(['receipt_render_history_id']);
        });
        Schema::dropIfExists('print_reprint_audits');
        Schema::dropIfExists('receipt_render_histories');
        Schema::dropIfExists('fiscal_invoices');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('receipt_templates');
    }
};

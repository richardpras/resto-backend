<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_guest_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('session_token', 64)->unique();
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('tables')->cascadeOnDelete();
            $table->string('qr_public_id', 64);
            $table->string('status', 16)->default('active');
            $table->timestamp('expires_at');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['table_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->foreignId('guest_session_id')
                ->nullable()
                ->after('table_id')
                ->constrained('qr_guest_sessions')
                ->nullOnDelete();
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('qr_pending_confirmation_ttl_minutes')
                ->default(20)
                ->after('require_customer_approval_for_adjustments');
        });
    }

    public function down(): void
    {
        Schema::table('qr_order_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('guest_session_id');
        });

        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropColumn('qr_pending_confirmation_ttl_minutes');
        });

        Schema::dropIfExists('qr_guest_sessions');
    }
};

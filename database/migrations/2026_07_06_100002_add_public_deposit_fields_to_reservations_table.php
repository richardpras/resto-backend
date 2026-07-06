<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM(
                'draft',
                'pending_deposit',
                'deposit_submitted',
                'confirmed',
                'checked_in',
                'seated',
                'completed',
                'cancelled',
                'no_show'
            ) NOT NULL DEFAULT 'draft'");
        }

        Schema::table('reservations', function (Blueprint $table): void {
            $table->string('source', 16)->default('staff')->after('status');
            $table->decimal('required_deposit_amount', 14, 2)->nullable()->after('source');
            $table->decimal('approved_deposit_amount', 14, 2)->nullable()->after('required_deposit_amount');
            $table->dateTime('deposit_reviewed_at')->nullable()->after('approved_deposit_amount');
            $table->unsignedBigInteger('deposit_reviewed_by')->nullable()->after('deposit_reviewed_at');
            $table->text('deposit_rejection_reason')->nullable()->after('deposit_reviewed_by');

            $table->foreign('deposit_reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropForeign(['deposit_reviewed_by']);
            $table->dropColumn([
                'source',
                'required_deposit_amount',
                'approved_deposit_amount',
                'deposit_reviewed_at',
                'deposit_reviewed_by',
                'deposit_rejection_reason',
            ]);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE reservations MODIFY COLUMN status ENUM(
                'draft',
                'confirmed',
                'checked_in',
                'seated',
                'completed',
                'cancelled',
                'no_show'
            ) NOT NULL DEFAULT 'draft'");
        }
    }
};

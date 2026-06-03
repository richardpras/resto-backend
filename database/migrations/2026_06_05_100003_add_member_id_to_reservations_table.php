<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            if (! Schema::hasColumn('reservations', 'member_id')) {
                $table->foreignId('member_id')->nullable()->after('customer_phone')->constrained('members')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            if (Schema::hasColumn('reservations', 'member_id')) {
                $table->dropConstrainedForeignId('member_id');
            }
        });
    }
};

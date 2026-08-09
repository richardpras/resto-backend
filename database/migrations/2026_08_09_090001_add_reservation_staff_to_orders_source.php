<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN source ENUM('pos', 'qr', 'reservation_public', 'reservation_staff') NOT NULL DEFAULT 'pos'");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE orders MODIFY COLUMN source ENUM('pos', 'qr', 'reservation_public') NOT NULL DEFAULT 'pos'");
        }
    }
};

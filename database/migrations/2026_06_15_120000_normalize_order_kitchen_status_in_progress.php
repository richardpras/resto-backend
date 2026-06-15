<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('orders')
            ->where('kitchen_status', 'in_progress')
            ->update(['kitchen_status' => 'cooking']);
    }

    public function down(): void
    {
        DB::table('orders')
            ->where('kitchen_status', 'cooking')
            ->update(['kitchen_status' => 'in_progress']);
    }
};

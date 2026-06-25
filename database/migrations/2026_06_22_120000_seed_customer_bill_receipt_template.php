<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('receipt_templates')
            ->where('outlet_id', 0)
            ->where('kind', 'customer_bill')
            ->where('code', 'default')
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();
        DB::table('receipt_templates')->insert([
            'outlet_id' => 0,
            'kind' => 'customer_bill',
            'code' => 'default',
            'version' => 1,
            'name' => 'Builtin customer_bill',
            'thermal_width_chars' => 42,
            'printer_profile_id' => null,
            'sections' => json_encode(['showTotals' => true, 'showItems' => true]),
            'defaults' => json_encode(['header' => 'BILL']),
            'is_active' => true,
            'is_default_fallback' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('receipt_templates')
            ->where('outlet_id', 0)
            ->where('kind', 'customer_bill')
            ->where('code', 'default')
            ->delete();
    }
};

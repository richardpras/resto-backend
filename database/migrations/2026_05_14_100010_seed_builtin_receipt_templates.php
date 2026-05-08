<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $kinds = [
            'customer_receipt',
            'kitchen_chit',
            'payment_receipt',
            'qr_receipt',
            'cashier_close_summary',
            'fiscal_invoice',
        ];

        foreach ($kinds as $kind) {
            DB::table('receipt_templates')->insert([
                'outlet_id' => 0,
                'kind' => $kind,
                'code' => 'default',
                'version' => 1,
                'name' => 'Builtin '.$kind,
                'thermal_width_chars' => 42,
                'printer_profile_id' => null,
                'sections' => json_encode(['showTotals' => true, 'showItems' => true]),
                'defaults' => json_encode(['header' => $kind === 'kitchen_chit' ? 'KITCHEN' : 'RECEIPT']),
                'is_active' => true,
                'is_default_fallback' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('receipt_templates')->where('outlet_id', 0)->where('code', 'default')->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['code' => '2130', 'name' => 'Gift Card Liability', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'gift_card_liability'],
            ['code' => '2135', 'name' => 'Store Credit Liability', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'store_credit_liability'],
            ['code' => '4190', 'name' => 'Gift Card Breakage Revenue', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'gift_card_breakage'],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('accounts')->where('code', $row['code'])->exists();
            if ($exists) {
                continue;
            }
            DB::table('accounts')->insert([
                'tenant_id' => null,
                'outlet_id' => null,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'subtype' => $row['subtype'],
                'category' => $row['category'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('accounts')->whereIn('code', ['2130', '2135', '4190'])->delete();
    }
};

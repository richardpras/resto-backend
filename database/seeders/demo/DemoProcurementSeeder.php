<?php

namespace Database\Seeders\Demo;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoProcurementSeeder extends Seeder
{
    public function run(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('purchase_requests_v2')) {
            return;
        }

        $now = now();
        $outlet = DemoSeederContext::outlets()->first();
        if ($outlet === null) {
            return;
        }

        $ingredientId = DB::table('ingredients')->where('outlet_id', $outlet->id)->value('id');
        if ($ingredientId === null) {
            return;
        }

        $supplierId = DB::table('suppliers')->value('id');
        if ($supplierId === null) {
            $supplierId = DB::table('suppliers')->insertGetId([
                'name' => 'Demo Supplier PT',
                'contact' => '021000000',
                'email' => 'supplier@demo.local',
                'address' => 'Jl. Supplier Demo 1',
                'notes' => 'Demo procurement supplier',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $prStatuses = ['draft', 'submitted', 'approved', 'approved', 'rejected'];

        for ($i = 1; $i <= 20; $i++) {
            $requestNo = sprintf('DEMO-PR-%04d', $i);
            $status = $prStatuses[$i % count($prStatuses)];

            DB::table('purchase_requests_v2')->updateOrInsert(
                ['request_no' => $requestNo],
                [
                    'outlet_id' => $outlet->id,
                    'requested_by' => 'Demo Manager',
                    'status' => $status,
                    'notes' => 'Demo purchase request',
                    'submitted_at' => $status !== 'draft' ? $now->copy()->subDays(30 - $i) : null,
                    'approved_at' => $status === 'approved' ? $now->copy()->subDays(28 - $i) : null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            $prId = (int) DB::table('purchase_requests_v2')->where('request_no', $requestNo)->value('id');

            DB::table('purchase_request_items_v2')->updateOrInsert(
                ['purchase_request_id' => $prId, 'inventory_item_id' => $ingredientId],
                [
                    'quantity' => 10 + $i,
                    'unit' => 'kg',
                    'estimated_cost' => (10 + $i) * 12000,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );

            $fromPr = $i <= 15;
            $poNumber = sprintf('DEMO-PO-%04d', $i);

            DB::table('purchase_orders')->updateOrInsert(
                ['number' => $poNumber],
                [
                    'purchase_request_id' => $fromPr ? $prId : null,
                    'supplier_id' => $supplierId,
                    'status' => $i % 4 === 0 ? 'received' : 'approved',
                    'order_date' => $now->copy()->subDays(28 - $i)->toDateString(),
                    'supplier_name' => 'Demo Supplier PT',
                    'notes' => $fromPr ? 'From PR' : 'Direct PO',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $poId = (int) DB::table('purchase_orders')->where('number', $poNumber)->value('id');

            DB::table('purchase_order_items')->updateOrInsert(
                ['purchase_order_id' => $poId, 'ingredient_id' => $ingredientId],
                [
                    'ordered_qty' => 10 + $i,
                    'received_qty' => $i % 3 === 0 ? (int) ((10 + $i) / 2) : 10 + $i,
                    'unit_price' => 12000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            if ($i % 2 === 0 && DB::getSchemaBuilder()->hasTable('goods_receiving_notes')) {
                DB::table('goods_receiving_notes')->updateOrInsert(
                    ['number' => sprintf('DEMO-GRN-%04d', $i)],
                    [
                        'purchase_order_id' => $poId,
                        'status' => 'posted',
                        'received_date' => $now->copy()->subDays(20 - $i)->toDateString(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }
}

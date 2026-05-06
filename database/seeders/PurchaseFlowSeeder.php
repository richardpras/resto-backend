<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PurchaseFlowSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('purchase_requests')->exists()) {
            return;
        }

        $now = now();

        $supplierIds = $this->seedSuppliers($now->toDateTimeString());
        $ingredientMap = DB::table('ingredients')
            ->whereIn('name', ['Rice', 'Chicken', 'Cooking Oil', 'Garlic'])
            ->pluck('id', 'name');

        if ($ingredientMap->count() < 4) {
            return;
        }

        $this->ensureAccountingAccounts($now->toDateTimeString());

        DB::transaction(function () use ($supplierIds, $ingredientMap, $now): void {
            // Flow A: PR -> PO -> GR -> Invoice -> Partial Payment
            $pr1 = DB::table('purchase_requests')->insertGetId([
                'number' => 'PR-202605-0001',
                'status' => 'approved',
                'request_date' => '2026-05-01',
                'outlet' => 'Main Outlet',
                'requested_by' => 'Kitchen Manager',
                'notes' => 'Weekly raw material replenishment',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('purchase_request_items')->insert([
                [
                    'purchase_request_id' => $pr1,
                    'ingredient_id' => $ingredientMap['Rice'],
                    'requested_qty' => 40,
                    'unit' => 'kg',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'purchase_request_id' => $pr1,
                    'ingredient_id' => $ingredientMap['Cooking Oil'],
                    'requested_qty' => 12,
                    'unit' => 'L',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            $po1 = DB::table('purchase_orders')->insertGetId([
                'purchase_request_id' => $pr1,
                'supplier_id' => $supplierIds['PT Sumber Pangan'],
                'number' => 'PO-202605-0001',
                'status' => 'completed',
                'order_date' => '2026-05-02',
                'supplier_name' => 'PT Sumber Pangan',
                'notes' => 'Deliver before noon',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $po1Rice = DB::table('purchase_order_items')->insertGetId([
                'purchase_order_id' => $po1,
                'ingredient_id' => $ingredientMap['Rice'],
                'ordered_qty' => 40,
                'received_qty' => 40,
                'unit_price' => 12000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $po1Oil = DB::table('purchase_order_items')->insertGetId([
                'purchase_order_id' => $po1,
                'ingredient_id' => $ingredientMap['Cooking Oil'],
                'ordered_qty' => 12,
                'received_qty' => 12,
                'unit_price' => 18000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $gr1 = DB::table('goods_receiving_notes')->insertGetId([
                'purchase_order_id' => $po1,
                'number' => 'GRN-202605-0001',
                'received_date' => '2026-05-03',
                'notes' => 'All items received in good condition',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('goods_receiving_note_items')->insert([
                [
                    'goods_receiving_note_id' => $gr1,
                    'purchase_order_item_id' => $po1Rice,
                    'ingredient_id' => $ingredientMap['Rice'],
                    'received_qty' => 40,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'goods_receiving_note_id' => $gr1,
                    'purchase_order_item_id' => $po1Oil,
                    'ingredient_id' => $ingredientMap['Cooking Oil'],
                    'received_qty' => 12,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            $inv1Total = 696000; // 40*12,000 + 12*18,000
            $inv1 = DB::table('purchase_invoices')->insertGetId([
                'purchase_order_id' => $po1,
                'goods_receiving_note_id' => $gr1,
                'number' => 'INV-202605-0001',
                'invoice_date' => '2026-05-04',
                'total' => $inv1Total,
                'tax' => 0,
                'status' => 'partial',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->createJournal('JE-PINV-202605-0001', 'purchase_invoice', $inv1, '2026-05-04', 'Purchase invoice INV-202605-0001', $inv1Total, 1300, 2100, $now);

            $pay1 = DB::table('purchase_invoice_payments')->insertGetId([
                'purchase_invoice_id' => $inv1,
                'payment_date' => '2026-05-05',
                'amount' => 300000,
                'payment_method' => 'cash',
                'reference_no' => 'PAY-202605-0001',
                'notes' => 'First installment',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->createJournal('JE-PPAY-202605-0001', 'purchase_invoice_payment', $pay1, '2026-05-05', 'Supplier payment INV-202605-0001', 300000, 2100, 1100, $now);

            // Flow B: PR -> PO -> GR -> Invoice -> Full Payment
            $pr2 = DB::table('purchase_requests')->insertGetId([
                'number' => 'PR-202605-0002',
                'status' => 'approved',
                'request_date' => '2026-05-06',
                'outlet' => 'Main Outlet',
                'requested_by' => 'Procurement Staff',
                'notes' => 'Protein stock refill',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('purchase_request_items')->insert([
                [
                    'purchase_request_id' => $pr2,
                    'ingredient_id' => $ingredientMap['Chicken'],
                    'requested_qty' => 20,
                    'unit' => 'kg',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'purchase_request_id' => $pr2,
                    'ingredient_id' => $ingredientMap['Garlic'],
                    'requested_qty' => 10,
                    'unit' => 'kg',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            $po2 = DB::table('purchase_orders')->insertGetId([
                'purchase_request_id' => $pr2,
                'supplier_id' => $supplierIds['CV Maju Bersama'],
                'number' => 'PO-202605-0002',
                'status' => 'completed',
                'order_date' => '2026-05-07',
                'supplier_name' => 'CV Maju Bersama',
                'notes' => 'Urgent for weekend demand',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $po2Chicken = DB::table('purchase_order_items')->insertGetId([
                'purchase_order_id' => $po2,
                'ingredient_id' => $ingredientMap['Chicken'],
                'ordered_qty' => 20,
                'received_qty' => 20,
                'unit_price' => 35000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $po2Garlic = DB::table('purchase_order_items')->insertGetId([
                'purchase_order_id' => $po2,
                'ingredient_id' => $ingredientMap['Garlic'],
                'ordered_qty' => 10,
                'received_qty' => 10,
                'unit_price' => 30000,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $gr2 = DB::table('goods_receiving_notes')->insertGetId([
                'purchase_order_id' => $po2,
                'number' => 'GRN-202605-0002',
                'received_date' => '2026-05-08',
                'notes' => 'Received by warehouse team',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('goods_receiving_note_items')->insert([
                [
                    'goods_receiving_note_id' => $gr2,
                    'purchase_order_item_id' => $po2Chicken,
                    'ingredient_id' => $ingredientMap['Chicken'],
                    'received_qty' => 20,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'goods_receiving_note_id' => $gr2,
                    'purchase_order_item_id' => $po2Garlic,
                    'ingredient_id' => $ingredientMap['Garlic'],
                    'received_qty' => 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);

            $inv2Total = 1000000; // 20*35,000 + 10*30,000
            $inv2 = DB::table('purchase_invoices')->insertGetId([
                'purchase_order_id' => $po2,
                'goods_receiving_note_id' => $gr2,
                'number' => 'INV-202605-0002',
                'invoice_date' => '2026-05-09',
                'total' => $inv2Total,
                'tax' => 0,
                'status' => 'paid',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->createJournal('JE-PINV-202605-0002', 'purchase_invoice', $inv2, '2026-05-09', 'Purchase invoice INV-202605-0002', $inv2Total, 1300, 2100, $now);

            $pay2 = DB::table('purchase_invoice_payments')->insertGetId([
                'purchase_invoice_id' => $inv2,
                'payment_date' => '2026-05-10',
                'amount' => $inv2Total,
                'payment_method' => 'bank',
                'reference_no' => 'TRX-20260510-7781',
                'notes' => 'Full transfer settlement',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->createJournal('JE-PPAY-202605-0002', 'purchase_invoice_payment', $pay2, '2026-05-10', 'Supplier payment INV-202605-0002', $inv2Total, 2100, 1100, $now);
        });
    }

    /**
     * @return array<string, int>
     */
    private function seedSuppliers(string $timestamp): array
    {
        $rows = [
            ['name' => 'PT Sumber Pangan', 'contact' => '08123456789', 'email' => 'info@sumberpangan.id', 'address' => 'Jl. Merdeka 12, Jakarta'],
            ['name' => 'CV Maju Bersama', 'contact' => '08198765432', 'email' => 'order@majubersama.id', 'address' => 'Jl. Sudirman 88, Bandung'],
            ['name' => 'UD Tani Makmur', 'contact' => '08111222333', 'email' => 'sales@tanimakmur.id', 'address' => 'Jl. Diponegoro 5, Surabaya'],
            ['name' => 'Fresh Daily Co', 'contact' => '08144556677', 'email' => 'hello@freshdaily.id', 'address' => 'Jl. Gatot Subroto 21, Jakarta'],
        ];

        $ids = [];
        foreach ($rows as $row) {
            $existingId = DB::table('suppliers')->where('name', $row['name'])->value('id');
            if ($existingId) {
                $ids[$row['name']] = (int) $existingId;
                continue;
            }

            $ids[$row['name']] = DB::table('suppliers')->insertGetId([
                'name' => $row['name'],
                'contact' => $row['contact'],
                'email' => $row['email'],
                'address' => $row['address'],
                'notes' => null,
                'status' => 'active',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        return $ids;
    }

    private function ensureAccountingAccounts(string $timestamp): void
    {
        $required = [
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset'],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'short_term_liability'],
        ];

        foreach ($required as $account) {
            $exists = DB::table('accounts')->where('code', $account['code'])->exists();
            if ($exists) {
                continue;
            }
            DB::table('accounts')->insert([
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'subtype' => $account['subtype'],
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    private function createJournal(
        string $journalNo,
        string $sourceType,
        int $sourceId,
        string $journalDate,
        string $description,
        float $amount,
        string|int $debitAccountCode,
        string|int $creditAccountCode,
        \Carbon\Carbon $now
    ): void {
        $debitAccountId = is_numeric((string) $debitAccountCode)
            ? DB::table('accounts')->where('code', (string) $debitAccountCode)->value('id')
            : DB::table('accounts')->where('code', (string) $debitAccountCode)->value('id');
        $creditAccountId = is_numeric((string) $creditAccountCode)
            ? DB::table('accounts')->where('code', (string) $creditAccountCode)->value('id')
            : DB::table('accounts')->where('code', (string) $creditAccountCode)->value('id');

        if (! $debitAccountId || ! $creditAccountId) {
            return;
        }

        $journalId = DB::table('journals')->insertGetId([
            'journal_no' => $journalNo,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'journal_date' => $journalDate,
            'status' => 'posted',
            'description' => $description,
            'outlet' => 'Main Outlet',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('journal_entries')->insert([
            [
                'journal_id' => $journalId,
                'account_id' => $debitAccountId,
                'debit' => $amount,
                'credit' => 0,
                'memo' => null,
                'line_no' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'journal_id' => $journalId,
                'account_id' => $creditAccountId,
                'debit' => 0,
                'credit' => $amount,
                'memo' => null,
                'line_no' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}

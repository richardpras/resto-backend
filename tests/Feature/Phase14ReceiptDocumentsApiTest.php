<?php

namespace Tests\Feature;

use App\Jobs\Print\ProcessPrintJob;
use App\Models\Modules\Print\Domain\FiscalInvoice;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrintReprintAudit;
use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Terminals\Support\TerminalOperationType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase14ReceiptDocumentsApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_render_with_fiscal_is_idempotent_without_force_regenerate(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('RR1');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $orderId = $this->createOrderRow((int) $outlet->id, null, 'ORD');

        Queue::fake();

        $payload = [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => $orderId,
            'issueFiscal' => true,
            'queuePrint' => false,
            'generatePdf' => false,
            'forceRegenerate' => false,
        ];

        $a = $this->postJson('/api/v1/print/documents/render', $payload)->assertOk();
        $b = $this->postJson('/api/v1/print/documents/render', $payload)->assertOk();

        $hidA = (int) $a->json('data.id');
        $hidB = (int) $b->json('data.id');
        $this->assertSame($hidA, $hidB);
        $this->assertSame(1, ReceiptRenderHistory::query()->count());
        $this->assertSame(1, FiscalInvoice::query()->where('outlet_id', $outlet->id)->count());
    }

    public function test_duplicate_render_with_queue_suppresses_extra_print_jobs(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('RR2');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $orderId = $this->createOrderRow((int) $outlet->id, null, 'ORD');

        Queue::fake();

        $payload = [
            'outletId' => (int) $outlet->id,
            'kind' => 'payment_receipt',
            'sourceType' => 'order',
            'sourceId' => $orderId,
            'issueFiscal' => false,
            'queuePrint' => true,
            'generatePdf' => false,
            'forceRegenerate' => false,
        ];

        $this->postJson('/api/v1/print/documents/render', $payload)->assertOk();
        $this->postJson('/api/v1/print/documents/render', $payload)->assertOk();

        $this->assertSame(1, PrintJob::query()->where('outlet_id', $outlet->id)->where('source_type', 'receipt_render')->count());
        // ShouldBeUnique + DB dedupe: idempotent render does not enqueue a second distinct job/work item.
        Queue::assertPushed(ProcessPrintJob::class, 1);
    }

    public function test_pdf_download_after_generate_pdf(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('RR3');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $orderId = $this->createOrderRow((int) $outlet->id, null, 'ORD');

        Queue::fake();

        $resp = $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'qr_receipt',
            'sourceType' => 'order',
            'sourceId' => $orderId,
            'issueFiscal' => false,
            'queuePrint' => false,
            'generatePdf' => true,
            'forceRegenerate' => false,
        ])->assertOk();

        $hid = (int) $resp->json('data.id');

        $this->get('/api/v1/print/documents/'.$hid.'/pdf')->assertOk();
    }

    public function test_reprint_increments_counter_and_writes_audit_row(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('RR4');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);
        $orderId = $this->createOrderRow((int) $outlet->id, null, 'ORD');

        Queue::fake();

        $hid = (int) $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => $orderId,
            'queuePrint' => true,
            'issueFiscal' => false,
        ])->assertOk()->json('data.id');

        $initialJobs = PrintJob::query()->where('outlet_id', $outlet->id)->where('source_type', 'receipt_render')->count();
        $this->assertGreaterThanOrEqual(1, $initialJobs);

        $this->postJson('/api/v1/print/documents/'.$hid.'/reprint', ['reason' => 'customer request'])->assertOk();

        $this->assertSame(1, (int) ReceiptRenderHistory::query()->find($hid)?->reprint_count);

        $this->assertTrue(
            PrintReprintAudit::query()->where('receipt_render_history_id', $hid)->where('action', 'reprint')->exists()
        );

        $this->assertGreaterThanOrEqual($initialJobs + 1, PrintJob::query()->where('outlet_id', $outlet->id)->where('source_type', 'receipt_render')->count());
    }

    public function test_split_receipt_uses_order_split_origin_for_fiscal(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('RR5');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $orderId = $this->createOrderRow((int) $outlet->id, null, 'ORD');
        $orderItemId = $this->createOrderItemRow($orderId, 'Noodles', 1, 9000);

        $splitId = (int) DB::table('order_splits')->insertGetId([
            'order_id' => $orderId,
            'split_type' => 'even',
            'label' => 'Guest A',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_split_items')->insert([
            'order_split_id' => $splitId,
            'order_item_id' => $orderItemId,
            'qty' => 1,
            'amount' => 9000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();

        $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => $orderId,
            'orderSplitId' => $splitId,
            'issueFiscal' => true,
            'queuePrint' => false,
        ])->assertOk();

        $this->assertDatabaseHas('fiscal_invoices', [
            'outlet_id' => $outlet->id,
            'source_type' => 'order_split',
            'source_id' => $splitId,
        ]);
    }

    public function test_render_for_unassigned_outlet_returns_validation_error(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outletAllowed = $this->createOutletFixture('ALW');
        $outletForbidden = $this->createOutletFixture('FRB');
        $this->assignUserToOutlets($user, [(int) $outletAllowed->id]);
        $orderId = $this->createOrderRow((int) $outletForbidden->id, null, 'ORD');

        Queue::fake();

        $resp = $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outletForbidden->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => $orderId,
            'queuePrint' => false,
        ]);

        $resp->assertStatus(422)->assertJsonValidationErrors(['outletId']);
    }

    public function test_sync_print_document_enqueue_duplicate_fingerprint_returns_duplicate_without_extra_job(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('RR6');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/terminals/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'p14-device-'.$outlet->id,
        ])->assertOk();

        $orderId = $this->createOrderRow((int) $outlet->id, null, 'ORD');
        Queue::fake();

        $hid = (int) $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => $orderId,
            'issueFiscal' => false,
            'queuePrint' => false,
        ])->assertOk()->json('data.id');

        $history = ReceiptRenderHistory::query()->findOrFail($hid);
        $history->deferred_replay_pending = true;
        $history->save();

        $fp = hash('sha256', 'p14-print-doc-'.$outlet->id);
        Queue::fake();

        $jobsBefore = PrintJob::query()->where('outlet_id', $outlet->id)->where('source_type', 'receipt_render')->count();

        $first = $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'p14-device-'.$outlet->id,
            'operations' => [
                [
                    'fingerprint' => $fp,
                    'operationType' => TerminalOperationType::PRINT_DOCUMENT_ENQUEUE,
                    'payload' => [
                        'renderHistoryId' => $hid,
                        'replayKey' => 'unit-replay-one',
                    ],
                ],
            ],
        ]);
        $first->assertOk()->assertJsonPath('data.results.0.status', 'applied');

        $second = $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'p14-device-'.$outlet->id,
            'operations' => [
                [
                    'fingerprint' => $fp,
                    'operationType' => TerminalOperationType::PRINT_DOCUMENT_ENQUEUE,
                    'payload' => [
                        'renderHistoryId' => $hid,
                        'replayKey' => 'unit-replay-one',
                    ],
                ],
            ],
        ]);

        $second->assertOk()->assertJsonPath('data.results.0.status', 'duplicate');

        $this->assertSame($jobsBefore + 1, PrintJob::query()->where('outlet_id', $outlet->id)->where('source_type', 'receipt_render')->count());
    }

    public function test_cashier_close_summary_render_succeeds(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('RR8');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $sessionId = (int) DB::table('pos_sessions')->insertGetId([
            'outlet_id' => (int) $outlet->id,
            'opened_by_user_id' => $user->id,
            'closed_by_user_id' => $user->id,
            'status' => 'closed',
            'opening_cash' => 100,
            'closing_cash' => 105,
            'cash_variance' => 5,
            'opened_at' => now()->subHour(),
            'closed_at' => now(),
            'notes' => 'close test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::fake();

        $this->postJson('/api/v1/print/documents/cashier-session-summary', [
            'outletId' => (int) $outlet->id,
            'posSessionId' => $sessionId,
            'queuePrint' => false,
            'generatePdf' => false,
            'issueFiscal' => false,
        ])->assertOk()->assertJsonPath('data.kind', 'cashier_close_summary');
    }

    public function test_old_client_occurred_at_rejected_stale_for_print_document_enqueue(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('RR7');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/terminals/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'p14-stale-'.$outlet->id,
        ])->assertOk();

        $orderId = $this->createOrderRow((int) $outlet->id, null, 'ORD');
        Queue::fake();
        $hid = (int) $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => $orderId,
            'queuePrint' => false,
        ])->assertOk()->json('data.id');

        $stale = CarbonImmutable::now()->utc()->subDays(40)->toIso8601String();

        $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'p14-stale-'.$outlet->id,
            'operations' => [
                [
                    'fingerprint' => hash('sha256', 'p14-old-'.$hid),
                    'clientOccurredAt' => $stale,
                    'operationType' => TerminalOperationType::PRINT_DOCUMENT_ENQUEUE,
                    'payload' => [
                        'renderHistoryId' => $hid,
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'rejected_stale');
    }

    private function createOutletFixture(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower($prefix).'-'.uniqid(),
        ]);
    }

    private function createOrderRow(int $outletId, ?int $sessionId, string $code): int
    {
        return (int) DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'pos_session_id' => $sessionId,
            'code' => $code.'-'.uniqid(),
            'source' => 'pos',
            'order_channel' => 'dine_in',
            'service_mode' => 'dine_in',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'kitchen_status' => 'queued',
            'subtotal' => 9000,
            'tax' => 0,
            'total' => 9000,
            'paid_total' => 0,
            'balance_due' => 9000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOrderItemRow(int $orderId, string $name, float $qty, float $price): int
    {
        $lineTotal = $qty * $price;

        return (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'item_id' => '1',
            'name' => $name,
            'emoji' => null,
            'qty' => $qty,
            'price' => $price,
            'line_total' => $lineTotal,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Terminals\Domain\TerminalSyncConflictEvent;
use App\Models\Modules\Terminals\Domain\TerminalSyncOperation;
use App\Modules\Terminals\Support\TerminalOperationType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase13TerminalsOfflineSyncApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_terminal_register_disable_and_revoked_device_blocks_sync(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('PTR');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $reg = $this->postJson('/api/v1/terminals/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'device-test-'.$outlet->id,
            'displayLabel' => 'Front Counter',
        ]);
        $reg->assertOk()->assertJsonPath('success', true);
        $terminalId = (int) $reg->json('data.id');

        $this->getJson('/api/v1/terminals?outletId='.(int) $outlet->id)->assertOk()
            ->assertJsonPath('data.0.deviceKey', 'device-test-'.$outlet->id);

        $this->postJson('/api/v1/terminals/'.$terminalId.'/disable')->assertOk()
            ->assertJsonPath('data.status', 'disabled');

        $sync = $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'device-test-'.$outlet->id,
            'operations' => [
                [
                    'fingerprint' => hash('sha256', 'noop-'.$outlet->id.'-'.Str::uuid()->toString()),
                    'operationType' => TerminalOperationType::PRINT_JOB_RETRY,
                    'payload' => [
                        'outletId' => (int) $outlet->id,
                        'printJobId' => 1,
                    ],
                ],
            ],
        ]);
        $sync->assertStatus(422)->assertJsonValidationErrors(['deviceKey']);
    }

    public function test_sync_batch_rejects_replay_outside_allowed_window_then_accepts_duplicate_fingerprint_when_applied(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('PVR');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/terminals/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'replay-device-'.$outlet->id,
        ])->assertOk();

        $orderId = $this->createOrderRow((int) $outlet->id, null, 'REP-'.$outlet->id);
        $ticketId = (int) KitchenTicket::query()->create([
            'outlet_id' => (int) $outlet->id,
            'order_id' => $orderId,
            'ticket_no' => 'KT-'.Str::upper(Str::random(8)),
            'status' => 'queued',
            'queued_at' => now(),
        ])->id;

        $staleOccurred = CarbonImmutable::now()->utc()->subDays(40)->toIso8601String();
        $fpStale = hash('sha256', 'stale-'.$ticketId.'-'.Str::uuid()->toString());

        $staleResp = $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'replay-device-'.$outlet->id,
            'operations' => [
                [
                    'fingerprint' => $fpStale,
                    'operationType' => TerminalOperationType::KITCHEN_TICKET_STATUS,
                    'clientOccurredAt' => $staleOccurred,
                    'payload' => [
                        'kitchenTicketId' => $ticketId,
                        'status' => 'in_progress',
                    ],
                ],
            ],
        ]);
        $staleResp->assertOk()->assertJsonPath('success', true);
        $this->assertSame('rejected_stale', $staleResp->json('data.results.0.status'));

        $fpApply = hash('sha256', 'dedupe-'.$ticketId);
        $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'replay-device-'.$outlet->id,
            'operations' => [
                [
                    'fingerprint' => $fpApply,
                    'operationType' => TerminalOperationType::KITCHEN_TICKET_STATUS,
                    'payload' => [
                        'kitchenTicketId' => $ticketId,
                        'status' => 'in_progress',
                    ],
                ],
            ],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'applied');

        $dupResp = $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'replay-device-'.$outlet->id,
            'operations' => [
                [
                    'fingerprint' => $fpApply,
                    'operationType' => TerminalOperationType::KITCHEN_TICKET_STATUS,
                    'payload' => [
                        'kitchenTicketId' => $ticketId,
                        'status' => 'in_progress',
                    ],
                ],
            ],
        ]);
        $dupResp->assertOk()->assertJsonPath('data.results.0.status', 'duplicate');
        $this->assertGreaterThanOrEqual(1, (int) $dupResp->json('data.results.0.duplicateReplayHits'));

        KitchenTicket::query()->whereKey($ticketId)->update(['status' => 'queued']);

        $freshTicket = KitchenTicket::query()->findOrFail($ticketId);
        $badExpected = CarbonImmutable::parse((string) $freshTicket->updated_at)->utc()->subMinutes(5)->toIso8601String();
        $fpConflict = hash('sha256', 'cf-'.$ticketId.'-'.Str::random(8));

        $confResp = $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'replay-device-'.$outlet->id,
            'operations' => [
                [
                    'fingerprint' => $fpConflict,
                    'operationType' => TerminalOperationType::KITCHEN_TICKET_STATUS,
                    'payload' => [
                        'kitchenTicketId' => $ticketId,
                        'status' => 'in_progress',
                        'expectedUpdatedAt' => $badExpected,
                    ],
                ],
            ],
        ]);
        $confResp->assertOk()->assertJsonPath('data.results.0.status', 'conflict');
        $this->assertGreaterThan(0, TerminalSyncConflictEvent::query()->where('outlet_id', $outlet->id)->count());
    }

    public function test_monitoring_metrics_surface_offline_counters(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('MOF');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        TerminalSyncOperation::query()->create([
            'outlet_id' => (int) $outlet->id,
            'terminal_device_id' => null,
            'operation_type' => TerminalOperationType::QR_ORDER_REJECT,
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
            'payload' => [],
            'status' => TerminalSyncOperation::STATUS_APPLIED,
            'client_occurred_at' => now(),
            'server_applied_at' => now(),
        ]);

        $this->getJson('/api/v1/monitoring/metrics?outletId='.(int) $outlet->id)->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.offlineResilience.syncOperationsApplied', 1)
            ->assertJsonPath('data.offlineResilience.registeredTerminals', 0);
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
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 0,
            'balance_due' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

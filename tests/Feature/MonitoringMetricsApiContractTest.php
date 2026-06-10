<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class MonitoringMetricsApiContractTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    /** @var list<string> */
    private const REQUIRED_DATA_KEYS = [
        'activePosSessions',
        'pendingKitchenTickets',
        'stalePayments',
        'printerQueue',
        'reconciliationFailures',
        'offlineResilience',
        'hardwareBridge',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_metrics_response_includes_contract_fields(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('MCC');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $response = $this->getJson('/api/v1/monitoring/metrics?outletId='.(int) $outlet->id);
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertIsArray($data);

        foreach (self::REQUIRED_DATA_KEYS as $key) {
            $this->assertArrayHasKey($key, $data, "Missing monitoring metrics contract key: {$key}");
        }

        $this->assertArrayHasKey('count', $data['activePosSessions']);
        $this->assertArrayHasKey('count', $data['pendingKitchenTickets']);
        $this->assertArrayHasKey('count', $data['stalePayments']);
        $this->assertArrayHasKey('count', $data['reconciliationFailures']);
        $this->assertArrayHasKey('pending', $data['printerQueue']);
        $this->assertArrayHasKey('failed', $data['printerQueue']);
        $this->assertArrayHasKey('syncReplayFailures', $data['offlineResilience']);
        $this->assertArrayHasKey('deadLetterCount', $data['hardwareBridge']);
    }

    private function createOutlet(string $prefix): Outlet
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
}

<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AuditCenterSearchTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_search_finds_document_number_in_payload(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Search Outlet');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        PosEventLog::query()->create([
            'outlet_id' => $outlet->id,
            'actor_user_id' => $user->id,
            'event_type' => 'purchase_invoice_created',
            'entity_type' => 'purchase_invoice',
            'entity_id' => 99,
            'payload' => ['invoiceNumber' => 'INV-2026-0099'],
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/audit-center/search?q=INV-2026-0099');

        $response->assertOk();
        $response->assertJsonPath('data.0.entityType', 'purchase_invoice');
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_search_requires_query(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/audit-center/search')->assertUnprocessable();
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'search-'.uniqid(),
        ]);
    }
}

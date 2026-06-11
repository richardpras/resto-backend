<?php

namespace Tests\Feature;

use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CashierStationValidationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class CashierStationReceiptRegressionTest extends TestCase
{
    use RefreshDatabase;
    use CashierStationValidationFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_receipt_document_includes_cashier_station_item_with_kitchen_items(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet();
        $stations = $this->provisionCashierValidationStations($outlet);
        $items = $this->createCashierValidationMenuItems($outlet, $stations);

        $order = $this->createConfirmedCashierValidationOrder(
            $outlet,
            $items['nasi'],
            $items['esTeh'],
            $items['rokok'],
        );

        $response = $this->postJson('/api/v1/print/documents/render', [
            'outletId' => (int) $outlet->id,
            'kind' => 'customer_receipt',
            'sourceType' => 'order',
            'sourceId' => $order['orderId'],
            'issueFiscal' => false,
            'queuePrint' => false,
            'generatePdf' => false,
            'forceRegenerate' => false,
        ])->assertOk();

        $history = ReceiptRenderHistory::query()->findOrFail((int) $response->json('data.id'));
        $context = is_array($history->context_snapshot) ? $history->context_snapshot : [];
        $lineNames = collect($context['lines'] ?? [])->pluck('name')->all();

        $this->assertEqualsCanonicalizing(
            ['Nasi Goreng', 'Es Teh', 'Rokok Marlboro'],
            $lineNames,
        );
        $this->assertSame($order['subtotal'], (float) ($context['total'] ?? 0));
        $this->assertStringContainsString('Rokok Marlboro', (string) $history->thermal_text);
    }

    /** @return array{0: \App\Models\User, 1: Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Cashier Receipt Validation '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'cashier-receipt-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }
}

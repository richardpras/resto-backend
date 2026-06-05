<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementSupplierTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_supplier_extended_fields_persist_on_create_and_update(): void
    {
        $this->actingAsProcurementUser();

        $create = $this->postJson('/api/v1/suppliers', [
            'name' => 'PT Extended Vendor',
            'status' => 'active',
            'paymentTermDays' => 30,
            'leadTimeDays' => 7,
            'taxNumber' => '01.234.567.8-999.000',
            'taxName' => 'PT Extended Vendor',
            'taxAddress' => 'Jl. Pajak No. 1',
            'contactPerson' => 'Budi',
            'contactPhone' => '08123456789',
            'contactEmail' => 'budi@vendor.test',
            'isActive' => true,
        ]);

        $create->assertCreated();
        $create->assertJsonPath('data.paymentTermDays', 30);
        $create->assertJsonPath('data.leadTimeDays', 7);
        $create->assertJsonPath('data.taxNumber', '01.234.567.8-999.000');
        $create->assertJsonPath('data.contactPerson', 'Budi');
        $create->assertJsonPath('data.isActive', true);

        $id = $create->json('data.id');

        $this->assertDatabaseHas('suppliers', [
            'id' => $id,
            'payment_term_days' => 30,
            'lead_time_days' => 7,
            'tax_number' => '01.234.567.8-999.000',
            'contact_person' => 'Budi',
            'is_active' => 1,
        ]);

        $this->patchJson("/api/v1/suppliers/{$id}", [
            'leadTimeDays' => 14,
            'contactPerson' => 'Ani',
        ])->assertOk()->assertJsonPath('data.leadTimeDays', 14)->assertJsonPath('data.contactPerson', 'Ani');
    }
}

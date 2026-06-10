<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Modules\Accounting\Services\AccountingHealthSeverityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingHealthSeverityTest extends TestCase
{
    use AccountingRemediationFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_health_endpoint_exposes_severity_fields(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Severity Health');

        for ($i = 1; $i <= 3; $i++) {
            AccountingPostingFailure::query()->create([
                'source_type' => 'order_payment',
                'source_id' => 1000 + $i,
                'outlet_id' => (int) $outlet->id,
                'error_code' => AccountingPostingFailure::ERROR_POSTING,
                'error_message' => 'Test failure',
                'status' => AccountingPostingFailure::STATUS_PENDING,
            ]);
        }

        $this->getJson('/api/v1/accounting/health?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.healthSeverity', 'warning')
            ->assertJsonPath('data.postingFailuresSeverity', 'warning')
            ->assertJsonStructure([
                'data' => [
                    'healthSeverity',
                    'postingFailuresSeverity',
                    'failureAgingBuckets',
                    'topFailureSources',
                    'priorityQueue',
                ],
            ]);
    }

    public function test_severity_engine_rules(): void
    {
        $engine = app(AccountingHealthSeverityEngine::class);

        $this->assertSame('warning', $engine->postingFailuresSeverity(3));
        $this->assertSame('high', $engine->postingFailuresSeverity(10));
        $this->assertSame('critical', $engine->postingFailuresSeverity(25));
        $this->assertSame('critical', $engine->giftCardVarianceSeverity(2));
        $this->assertSame('critical', $engine->inventoryVarianceSeverity(6));
    }
}

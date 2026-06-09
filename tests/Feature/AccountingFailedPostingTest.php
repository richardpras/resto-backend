<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingFailedPostingTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;
    use AccountingRemediationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_missing_mapping_creates_posting_failure_record(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Failed Posting');

        app(JournalPostingService::class)->postForOrderPayment(
            99,
            1,
            (int) $outlet->id,
            50000,
            0,
        );

        $this->assertDatabaseHas('accounting_posting_failures', [
            'source_type' => 'order_payment',
            'source_id' => 99,
            'status' => AccountingPostingFailure::STATUS_PENDING,
            'error_code' => AccountingPostingFailure::ERROR_MISSING_MAPPING,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingHealthPriorityQueueTest extends TestCase
{
    use AccountingRemediationFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_priority_queue_sorted_with_action_urls(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Priority Queue');

        for ($i = 1; $i <= 7; $i++) {
            AccountingPostingFailure::query()->create([
                'source_type' => 'order_payment',
                'source_id' => 2000 + $i,
                'outlet_id' => (int) $outlet->id,
                'error_code' => AccountingPostingFailure::ERROR_POSTING,
                'error_message' => 'Failure',
                'status' => AccountingPostingFailure::STATUS_PENDING,
            ]);
        }

        $response = $this->getJson('/api/v1/accounting/health?outletId='.(int) $outlet->id)->assertOk();
        $queue = $response->json('data.priorityQueue');
        $this->assertIsArray($queue);
        $this->assertNotEmpty($queue);
        $this->assertSame('Posting Failures', $queue[0]['title']);
        $this->assertSame('/accounting?tab=health', $queue[0]['actionUrl']);
        $this->assertContains($queue[0]['priority'], ['warning', 'high', 'critical']);
    }
}

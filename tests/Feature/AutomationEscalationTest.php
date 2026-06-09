<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\AutomationAlert;
use App\Models\Modules\Menu\Domain\AutomationEscalationRule;
use App\Models\Modules\Menu\Domain\AutomationNotification;
use App\Modules\Menu\Services\EscalationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class AutomationEscalationTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    public function test_escalation_notifies_on_day_offset(): void
    {
        $outlet = $this->createValuationOutlet();
        app(EscalationService::class)->ensureDefaultEscalationRules((int) $outlet->id);

        AutomationAlert::query()->create([
            'outlet_id' => $outlet->id,
            'alert_type' => 'star_to_dog',
            'severity' => 'critical',
            'title' => 'Critical',
            'description' => 'Escalation test',
            'status' => 'open',
            'triggered_at' => now()->subDays(1),
        ]);

        $results = app(EscalationService::class)->runEscalations((int) $outlet->id);

        $this->assertNotEmpty($results);
        $this->assertTrue(AutomationNotification::query()->where('outlet_id', $outlet->id)->exists());
        $this->assertGreaterThanOrEqual(4, AutomationEscalationRule::query()->count());
    }
}

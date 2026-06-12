<?php

namespace Database\Seeders\Support;

/**
 * DEMO-DATA-SEEDER-03 — idempotent production demo patch orchestrator.
 */
final class DemoProductionDemoPatch03
{
    public static function apply(): void
    {
        DemoCustomerLifecycleReadinessPatch::apply();
        DemoShiftCloseReadinessPatch::apply();
        DemoInventoryProcurementReadinessPatch::apply();
        DemoMenuIntelligenceReadinessPatch::apply();
    }
}

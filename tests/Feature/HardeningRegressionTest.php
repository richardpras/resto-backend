<?php

namespace Tests\Feature;

use App\Modules\Menu\Jobs\CreateDailyAnalyticsSnapshotJob;
use App\Modules\Menu\Services\MenuHealthService;
use App\Modules\Menu\Services\MenuIntelligenceCacheService;
use App\Modules\Menu\Services\SnapshotRetentionService;
use Tests\TestCase;

class HardeningRegressionTest extends TestCase
{
    public function test_hardening_services_are_registered(): void
    {
        $this->assertTrue(class_exists(MenuIntelligenceCacheService::class));
        $this->assertTrue(class_exists(SnapshotRetentionService::class));
        $this->assertTrue(class_exists(MenuHealthService::class));
        $this->assertTrue(class_exists(CreateDailyAnalyticsSnapshotJob::class));
    }
}

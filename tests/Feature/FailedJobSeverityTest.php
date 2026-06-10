<?php

namespace Tests\Feature;

use App\Modules\System\Services\FailedJobSeverityEngine;
use Tests\TestCase;

class FailedJobSeverityTest extends TestCase
{
    public function test_aggregate_health_rules(): void
    {
        $engine = app(FailedJobSeverityEngine::class);

        $this->assertSame(
            FailedJobSeverityEngine::TIER_HEALTHY,
            $engine->aggregateHealth(['failedJobs' => 2, 'criticalFailures' => 0, 'oldestFailureMinutes' => 5]),
        );

        $this->assertSame(
            FailedJobSeverityEngine::TIER_WARNING,
            $engine->aggregateHealth(['failedJobs' => 4, 'criticalFailures' => 1, 'oldestFailureMinutes' => 5]),
        );

        $this->assertSame(
            FailedJobSeverityEngine::TIER_HIGH,
            $engine->aggregateHealth(['failedJobs' => 11, 'criticalFailures' => 1, 'oldestFailureMinutes' => 5]),
        );

        $this->assertSame(
            FailedJobSeverityEngine::TIER_CRITICAL,
            $engine->aggregateHealth(['failedJobs' => 8, 'criticalFailures' => 6, 'oldestFailureMinutes' => 10]),
        );

        $this->assertSame(
            FailedJobSeverityEngine::TIER_CRITICAL,
            $engine->aggregateHealth(['failedJobs' => 2, 'criticalFailures' => 1, 'oldestFailureMinutes' => 45]),
        );
    }

    public function test_job_class_classification(): void
    {
        $engine = app(FailedJobSeverityEngine::class);

        $this->assertSame(
            FailedJobSeverityEngine::JOB_TIER_CRITICAL,
            $engine->classifyJobClass('RecoverStalePaymentsJob'),
        );

        $this->assertSame(
            FailedJobSeverityEngine::JOB_TIER_WARNING,
            $engine->classifyJobClass('CreateDailyAnalyticsSnapshotJob'),
        );
    }
}

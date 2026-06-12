<?php

namespace Database\Seeders\Support;

use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\System\Domain\BugReport;
use App\Models\User;
use Database\Seeders\Demo\DemoSeederContext;

/**
 * DEMO-DATA-SEEDER-03 — menu intelligence snapshots, audit/notification refresh.
 */
final class DemoMenuIntelligenceReadinessPatch
{
    public static function apply(): void
    {
        foreach (DemoSeederContext::outlets() as $outlet) {
            self::seedMenuEngineeringSnapshots($outlet);
            self::seedFlowNotificationsAndAudit($outlet);
            self::seedQrPageBugReport($outlet);
        }
    }

    private static function seedMenuEngineeringSnapshots(Outlet $outlet): void
    {
        $snapshotDate = DemoPatch03Support::baseTime()->toDateString();
        $items = MenuItem::query()->where('outlet_id', $outlet->id)->where('available', true)->limit(12)->get();
        $classifications = ['star', 'plowhorse', 'puzzle', 'dog'];

        foreach ($items as $index => $item) {
            MenuEngineeringSnapshot::query()->updateOrCreate(
                [
                    'outlet_id' => $outlet->id,
                    'menu_item_id' => $item->id,
                    'snapshot_date' => $snapshotDate,
                ],
                [
                    'quantity_sold' => 40 + ($index * 7),
                    'popularity_percent' => min(95, 12 + ($index * 6)),
                    'contribution_margin' => (float) $item->price * 0.35,
                    'margin_percent' => 35 + ($index % 4) * 3,
                    'classification' => $classifications[$index % count($classifications)],
                ],
            );
        }

        PosEventLog::query()->updateOrCreate(
            [
                'outlet_id' => $outlet->id,
                'entity_type' => 'menu_engineering_snapshot',
                'entity_id' => (int) $outlet->id,
                'event_type' => 'menu_engineering_snapshot_created',
            ],
            [
                'payload' => ['demoPatch' => '03', 'snapshotDate' => $snapshotDate],
                'occurred_at' => now()->subHours(3),
            ],
        );
    }

    private static function seedFlowNotificationsAndAudit(Outlet $outlet): void
    {
        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $admin = User::query()->where('email', 'admin@restohub.demo')->first();
        if ($admin === null) {
            return;
        }

        $alerts = [
            ['qr_order_adjusted', 'QR order adjusted by cashier', 'orders'],
            ['shift_close_warning', 'Shift close completed with warnings', 'monitoring'],
            ['cash_variance_detected', 'Cash variance detected on last close', 'accounting'],
            ['inventory_posting_failed', 'Inventory posting failed', 'inventory'],
            ['duplicate_order_prevented', 'Duplicate POS order prevented', 'pos'],
            ['qr_order_linked', 'QR order linked to POS bill', 'orders'],
        ];

        foreach ($alerts as $index => [$sourceType, $title, $module]) {
            UserNotification::query()->updateOrCreate(
                [
                    'outlet_id' => $outlet->id,
                    'user_id' => (int) $admin->id,
                    'source_module' => $module,
                    'source_type' => $sourceType,
                    'source_id' => "{$prefix}-flow-{$index}",
                ],
                [
                    'severity' => str_contains($sourceType, 'failed') || str_contains($sourceType, 'variance')
                        ? UserNotification::SEVERITY_WARNING
                        : UserNotification::SEVERITY_INFO,
                    'title' => $title,
                    'message' => "{$title} — {$prefix} demo",
                    'action_url' => '/system/health',
                    'metadata' => ['demoPatch' => '03'],
                ],
            );

            PosEventLog::query()->updateOrCreate(
                [
                    'outlet_id' => $outlet->id,
                    'entity_type' => 'demo_flow',
                    'entity_id' => $index,
                    'event_type' => $sourceType,
                ],
                [
                    'actor_user_id' => $admin->id,
                    'payload' => ['demoReference' => "{$prefix}-{$sourceType}", 'demoPatch' => '03'],
                    'occurred_at' => now()->subMinutes(30 + $index),
                ],
            );
        }
    }

    private static function seedQrPageBugReport(Outlet $outlet): void
    {
        $prefix = DemoPatch03Support::outletPrefix($outlet);
        $admin = User::query()->where('email', 'admin@restohub.demo')->first();
        if ($admin === null) {
            return;
        }

        BugReport::query()->updateOrCreate(
            [
                'outlet_id' => $outlet->id,
                'title' => "{$prefix} QR order page display issue",
            ],
            [
                'reporter_user_id' => $admin->id,
                'message' => 'Timeline labels overlap on narrow screens when viewing adjusted QR orders.',
                'severity' => BugReport::SEVERITY_LOW,
                'status' => BugReport::STATUS_OPEN,
                'current_route' => '/qr/order/'.urlencode("{$prefix}-QRO-ADJUSTED"),
                'browser' => 'Mobile Safari',
                'user_agent' => 'DemoPatch03/1.0',
                'viewport' => '390x844',
                'app_version' => 'demo',
                'diagnostics_json' => ['source' => 'qr_order_page', 'requestCode' => "{$prefix}-QRO-ADJUSTED"],
            ],
        );
    }
}

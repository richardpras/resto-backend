<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyNotification;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyNotificationAnalyticsService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array{
     *     notificationsCount: int,
     *     sentNotifications: int,
     *     failedNotifications: int,
     *     notificationSummary: list<array{eventType: string, count: int}>
     * }
     */
    public function summary(?User $user, int $outletId): array
    {
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $base = LoyaltyNotification::query()->where('outlet_id', $outletId);

        $notificationsCount = (int) (clone $base)->count();
        $sentNotifications = (int) (clone $base)
            ->where('status', LoyaltyNotification::STATUS_SENT)
            ->count();
        $failedNotifications = (int) (clone $base)
            ->where('status', LoyaltyNotification::STATUS_FAILED)
            ->count();

        $summaryRows = DB::table('loyalty_notifications')
            ->select('event_type', DB::raw('COUNT(*) as aggregate'))
            ->where('outlet_id', $outletId)
            ->groupBy('event_type')
            ->orderBy('event_type')
            ->get();

        $notificationSummary = $summaryRows->map(fn ($row) => [
            'eventType' => (string) $row->event_type,
            'count' => (int) $row->aggregate,
        ])->values()->all();

        return [
            'notificationsCount' => $notificationsCount,
            'sentNotifications' => $sentNotifications,
            'failedNotifications' => $failedNotifications,
            'notificationSummary' => $notificationSummary,
        ];
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($allowed !== null && ! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outlet is not allowed for this user.'],
            ]);
        }
    }
}

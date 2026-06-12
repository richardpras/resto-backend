<?php

namespace App\Modules\ShiftClose\Services;

use App\Modules\Notifications\Services\NotificationService;

class ShiftCloseNotificationAdapter
{
    public const TYPE_CLOSE_FAILED = 'shift_close_failed';

    public const TYPE_CASH_VARIANCE = 'cash_variance_detected';

    public const TYPE_REQUIRES_REVIEW = 'shift_close_requires_review';

    /** @var list<string> */
    private const RECIPIENT_PERMISSIONS = ['finance.shift_close', 'settings.manage', 'accounting.manage'];

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function notifyCloseFailed(int $outletId, string $reason, array $metadata = []): void
    {
        $this->fanOut(
            $outletId,
            self::TYPE_CLOSE_FAILED,
            'Shift close failed',
            $reason,
            '/shift-close',
            $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public function notifyCashVariance(int $outletId, float $variance, array $metadata = []): void
    {
        $this->fanOut(
            $outletId,
            self::TYPE_CASH_VARIANCE,
            'Cash variance detected',
            sprintf('Shift close cash variance: %s', number_format($variance, 2, '.', ',')),
            '/shift-close',
            array_merge($metadata, ['variance' => $variance]),
        );
    }

    /** @param array<string, mixed> $metadata */
    public function notifyRequiresReview(int $outletId, string $message, array $metadata = []): void
    {
        $this->fanOut(
            $outletId,
            self::TYPE_REQUIRES_REVIEW,
            'Shift close requires review',
            $message,
            '/shift-close',
            $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function fanOut(
        int $outletId,
        string $sourceType,
        string $title,
        string $message,
        string $actionUrl,
        array $metadata,
    ): void {
        foreach (self::RECIPIENT_PERMISSIONS as $permission) {
            $this->notificationService->fanOut(
                $outletId,
                $permission,
                str_contains($sourceType, 'failed') ? 'critical' : 'warning',
                'shift_close',
                $sourceType,
                (string) $outletId.'-'.now()->timestamp,
                $title,
                $message,
                $actionUrl,
                $metadata,
            );
        }
    }
}

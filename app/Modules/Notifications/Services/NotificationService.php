<?php

namespace App\Modules\Notifications\Services;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class NotificationService
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipientResolver,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function create(
        int $outletId,
        int $userId,
        string $severity,
        string $sourceModule,
        string $sourceType,
        string $sourceId,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?array $metadata = null,
    ): UserNotification {
        return UserNotification::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'outlet_id' => $outletId,
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
            [
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'metadata' => $metadata,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function fanOut(
        int $outletId,
        ?string $permissionCode,
        string $severity,
        string $sourceModule,
        string $sourceType,
        string $sourceId,
        string $title,
        string $message,
        ?string $actionUrl = null,
        ?array $metadata = null,
    ): Collection {
        $recipients = $this->recipientResolver->usersForOutlet($outletId, $permissionCode);

        return $recipients->map(fn (User $user): UserNotification => $this->create(
            $outletId,
            (int) $user->id,
            $severity,
            $sourceModule,
            $sourceType,
            $sourceId,
            $title,
            $message,
            $actionUrl,
            $metadata,
        ));
    }

    public function markRead(User $user, int $notificationId): UserNotification
    {
        $notification = $this->findForUser($user, $notificationId);
        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return $notification->fresh();
    }

    public function markAllRead(User $user, ?int $outletId = null): int
    {
        $query = UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at');

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        return $query->update(['read_at' => now()]);
    }

    public function unreadCount(User $user, ?int $outletId = null): int
    {
        $query = UserNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at');

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        return (int) $query->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, UserNotification>
     */
    public function listForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = UserNotification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        if (! empty($filters['unread']) && filter_var($filters['unread'], FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('read_at');
        }

        if (! empty($filters['severity']) && is_string($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (! empty($filters['source']) && is_string($filters['source'])) {
            $query->where('source_module', $filters['source']);
        }

        if (! empty($filters['outletId'])) {
            $outletId = (int) $filters['outletId'];
            if ($outletId > 0) {
                $query->where('outlet_id', $outletId);
            }
        }

        if (! empty($filters['dateFrom']) && is_string($filters['dateFrom'])) {
            $query->whereDate('created_at', '>=', $filters['dateFrom']);
        }

        if (! empty($filters['dateTo']) && is_string($filters['dateTo'])) {
            $query->whereDate('created_at', '<=', $filters['dateTo']);
        }

        $perPage = min(100, max(1, (int) ($filters['limit'] ?? $filters['perPage'] ?? 20)));

        return $query->paginate($perPage);
    }

    private function findForUser(User $user, int $notificationId): UserNotification
    {
        $notification = UserNotification::query()
            ->where('user_id', $user->id)
            ->where('id', $notificationId)
            ->first();

        if ($notification === null) {
            throw ValidationException::withMessages([
                'notification' => ['Notification not found.'],
            ]);
        }

        return $notification;
    }
}

<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Notifications\Http\Requests\ListUserNotificationsRequest;
use App\Modules\Notifications\Http\Resources\UserNotificationResource;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserNotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(ListUserNotificationsRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $paginator = $this->notificationService->listForUser($user, $request->validated());

        return response()->json([
            'data' => UserNotificationResource::collection($paginator->items()),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $request->validate([
            'outletId' => ['sometimes', 'integer', 'min:1'],
        ]);

        $user = $this->resolveUser($request);
        $outletId = $request->has('outletId') ? (int) $request->query('outletId') : null;

        return response()->json([
            'count' => $this->notificationService->unreadCount($user, $outletId),
        ]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $user = $this->resolveUser($request);
        $updated = $this->notificationService->markRead($user, $notification);

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => new UserNotificationResource($updated),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->validate([
            'outletId' => ['sometimes', 'integer', 'min:1'],
        ]);

        $user = $this->resolveUser($request);
        $outletId = $request->has('outletId') ? (int) $request->input('outletId') : null;
        $count = $this->notificationService->markAllRead($user, $outletId);

        return response()->json([
            'message' => 'All notifications marked as read.',
            'count' => $count,
        ]);
    }

    private function resolveUser(Request $request): User
    {
        $user = $request->user('api') ?? $request->user();
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        return $user;
    }
}

<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyNotification;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyNotificationResource;
use App\Modules\LoyaltyEngine\Services\LoyaltyNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyNotificationController extends Controller
{
    public function __construct(
        private readonly LoyaltyNotificationService $notificationService,
    ) {}

    public function indexForMember(Request $request, Member $member): JsonResponse
    {
        $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
        ]);

        $notifications = $this->notificationService->listForMember(
            $this->resolveUser($request),
            (int) $member->id,
            (int) $request->query('outletId'),
        );

        return response()->json([
            'data' => LoyaltyNotificationResource::collection($notifications),
        ]);
    }

    public function markRead(Request $request, LoyaltyNotification $notification): JsonResponse
    {
        $updated = $this->notificationService->markRead(
            $this->resolveUser($request),
            (int) $notification->id,
        );

        return response()->json([
            'message' => 'Notification marked as read.',
            'data' => new LoyaltyNotificationResource($updated),
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyRewardRedemption;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateLoyaltyRewardRedemptionStatusRequest;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyRewardRedemptionResource;
use App\Modules\LoyaltyEngine\Services\LoyaltyRewardRedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyRewardRedemptionController extends Controller
{
    public function __construct(
        private readonly LoyaltyRewardRedemptionService $redemptionService,
    ) {}

    public function updateStatus(
        UpdateLoyaltyRewardRedemptionStatusRequest $request,
        LoyaltyRewardRedemption $loyaltyRewardRedemption,
    ): JsonResponse {
        $updated = $this->redemptionService->updateStatus(
            $this->resolveUser($request),
            (int) $loyaltyRewardRedemption->id,
            (string) $request->validated('status'),
        );

        return response()->json([
            'message' => 'Redemption status updated.',
            'data' => new LoyaltyRewardRedemptionResource($updated),
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

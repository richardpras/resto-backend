<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyReward;
use App\Modules\LoyaltyEngine\Http\Requests\SetLoyaltyRewardActivationRequest;
use App\Modules\LoyaltyEngine\Http\Requests\StoreLoyaltyRewardRequest;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateLoyaltyRewardRequest;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyRewardResource;
use App\Modules\LoyaltyEngine\Services\LoyaltyRewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoyaltyRewardController extends Controller
{
    public function __construct(
        private readonly LoyaltyRewardService $rewardService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
        ]);

        $isActive = null;
        if ($request->has('isActive')) {
            $isActive = filter_var($request->query('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $rewards = $this->rewardService->list(
            $this->resolveUser($request),
            (int) $request->query('outletId'),
            $isActive,
        );

        return response()->json([
            'data' => LoyaltyRewardResource::collection($rewards),
        ]);
    }

    public function store(StoreLoyaltyRewardRequest $request): JsonResponse
    {
        $reward = $this->rewardService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty reward created successfully.',
            'data' => new LoyaltyRewardResource($reward),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, LoyaltyReward $loyaltyReward): JsonResponse
    {
        $reward = $this->rewardService->findScoped($this->resolveUser($request), (int) $loyaltyReward->id);
        abort_if($reward === null, Response::HTTP_NOT_FOUND, 'Loyalty reward not found.');

        return response()->json([
            'data' => new LoyaltyRewardResource($reward),
        ]);
    }

    public function update(UpdateLoyaltyRewardRequest $request, LoyaltyReward $loyaltyReward): JsonResponse
    {
        $reward = $this->rewardService->findScoped($this->resolveUser($request), (int) $loyaltyReward->id);
        abort_if($reward === null, Response::HTTP_NOT_FOUND, 'Loyalty reward not found.');

        $updated = $this->rewardService->update(
            $this->resolveUser($request),
            $reward,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty reward updated successfully.',
            'data' => new LoyaltyRewardResource($updated),
        ]);
    }

    public function setActivation(SetLoyaltyRewardActivationRequest $request, LoyaltyReward $loyaltyReward): JsonResponse
    {
        $reward = $this->rewardService->findScoped($this->resolveUser($request), (int) $loyaltyReward->id);
        abort_if($reward === null, Response::HTTP_NOT_FOUND, 'Loyalty reward not found.');

        $updated = $this->rewardService->setActive(
            $this->resolveUser($request),
            $reward,
            (bool) $request->validated('isActive'),
        );

        return response()->json([
            'message' => 'Loyalty reward activation updated.',
            'data' => new LoyaltyRewardResource($updated),
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

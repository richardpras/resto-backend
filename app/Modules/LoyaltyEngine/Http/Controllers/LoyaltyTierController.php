<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use App\Modules\LoyaltyEngine\Http\Requests\SetLoyaltyTierActivationRequest;
use App\Modules\LoyaltyEngine\Http\Requests\StoreLoyaltyTierRequest;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateLoyaltyTierRequest;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyTierResource;
use App\Modules\LoyaltyEngine\Services\LoyaltyTierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoyaltyTierController extends Controller
{
    public function __construct(
        private readonly LoyaltyTierService $loyaltyTierService,
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

        $tiers = $this->loyaltyTierService->list(
            $this->resolveUser($request),
            (int) $request->query('outletId'),
            $isActive,
        );

        return response()->json([
            'data' => LoyaltyTierResource::collection($tiers),
        ]);
    }

    public function store(StoreLoyaltyTierRequest $request): JsonResponse
    {
        $tier = $this->loyaltyTierService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty tier created successfully.',
            'data' => new LoyaltyTierResource($tier),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, LoyaltyTier $loyaltyTier): JsonResponse
    {
        $tier = $this->loyaltyTierService->findScoped($this->resolveUser($request), (int) $loyaltyTier->id);
        abort_if($tier === null, Response::HTTP_NOT_FOUND, 'Loyalty tier not found.');

        return response()->json([
            'data' => new LoyaltyTierResource($tier),
        ]);
    }

    public function update(UpdateLoyaltyTierRequest $request, LoyaltyTier $loyaltyTier): JsonResponse
    {
        $tier = $this->loyaltyTierService->findScoped($this->resolveUser($request), (int) $loyaltyTier->id);
        abort_if($tier === null, Response::HTTP_NOT_FOUND, 'Loyalty tier not found.');

        $updated = $this->loyaltyTierService->update(
            $this->resolveUser($request),
            $tier,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty tier updated successfully.',
            'data' => new LoyaltyTierResource($updated),
        ]);
    }

    public function setActivation(SetLoyaltyTierActivationRequest $request, LoyaltyTier $loyaltyTier): JsonResponse
    {
        $tier = $this->loyaltyTierService->findScoped($this->resolveUser($request), (int) $loyaltyTier->id);
        abort_if($tier === null, Response::HTTP_NOT_FOUND, 'Loyalty tier not found.');

        $updated = $this->loyaltyTierService->setActive(
            $this->resolveUser($request),
            $tier,
            (bool) $request->validated('isActive'),
        );

        return response()->json([
            'message' => 'Loyalty tier activation updated.',
            'data' => new LoyaltyTierResource($updated),
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

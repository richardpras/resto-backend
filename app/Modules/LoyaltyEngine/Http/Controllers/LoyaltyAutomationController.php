<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyAutomation;
use App\Modules\LoyaltyEngine\Http\Requests\SetLoyaltyAutomationActivationRequest;
use App\Modules\LoyaltyEngine\Http\Requests\StoreLoyaltyAutomationRequest;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateLoyaltyAutomationRequest;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyAutomationLogResource;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyAutomationResource;
use App\Modules\LoyaltyEngine\Services\LoyaltyAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoyaltyAutomationController extends Controller
{
    public function __construct(
        private readonly LoyaltyAutomationService $automationService,
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

        $rows = $this->automationService->list(
            $this->resolveUser($request),
            (int) $request->query('outletId'),
            $isActive,
        );

        return response()->json([
            'data' => LoyaltyAutomationResource::collection($rows),
        ]);
    }

    public function store(StoreLoyaltyAutomationRequest $request): JsonResponse
    {
        $automation = $this->automationService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty automation created successfully.',
            'data' => new LoyaltyAutomationResource($automation),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, LoyaltyAutomation $loyaltyAutomation): JsonResponse
    {
        $automation = $this->automationService->findScoped(
            $this->resolveUser($request),
            (int) $loyaltyAutomation->id,
        );
        abort_if($automation === null, Response::HTTP_NOT_FOUND, 'Loyalty automation not found.');

        return response()->json([
            'data' => new LoyaltyAutomationResource($automation),
        ]);
    }

    public function update(UpdateLoyaltyAutomationRequest $request, LoyaltyAutomation $loyaltyAutomation): JsonResponse
    {
        $automation = $this->automationService->findScoped(
            $this->resolveUser($request),
            (int) $loyaltyAutomation->id,
        );
        abort_if($automation === null, Response::HTTP_NOT_FOUND, 'Loyalty automation not found.');

        $updated = $this->automationService->update(
            $this->resolveUser($request),
            $automation,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty automation updated successfully.',
            'data' => new LoyaltyAutomationResource($updated),
        ]);
    }

    public function setActivation(SetLoyaltyAutomationActivationRequest $request, LoyaltyAutomation $loyaltyAutomation): JsonResponse
    {
        $automation = $this->automationService->findScoped(
            $this->resolveUser($request),
            (int) $loyaltyAutomation->id,
        );
        abort_if($automation === null, Response::HTTP_NOT_FOUND, 'Loyalty automation not found.');

        $updated = $this->automationService->setActive(
            $this->resolveUser($request),
            $automation,
            (bool) $request->validated('isActive'),
        );

        return response()->json([
            'message' => 'Loyalty automation activation updated.',
            'data' => new LoyaltyAutomationResource($updated),
        ]);
    }

    public function logs(Request $request, LoyaltyAutomation $loyaltyAutomation): JsonResponse
    {
        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $automation = $this->automationService->findScoped(
            $this->resolveUser($request),
            (int) $loyaltyAutomation->id,
        );
        abort_if($automation === null, Response::HTTP_NOT_FOUND, 'Loyalty automation not found.');

        $logs = $this->automationService->logs(
            $this->resolveUser($request),
            $automation,
            (int) ($request->query('limit') ?? 50),
        );

        return response()->json([
            'data' => LoyaltyAutomationLogResource::collection($logs),
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Modules\LoyaltyEngine\Http\Requests\SetLoyaltyProgramActivationRequest;
use App\Modules\LoyaltyEngine\Http\Requests\StoreLoyaltyProgramRequest;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateLoyaltyProgramRequest;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyProgramResource;
use App\Modules\LoyaltyEngine\Services\LoyaltyProgramManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoyaltyProgramController extends Controller
{
    public function __construct(
        private readonly LoyaltyProgramManagementService $managementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $isActive = null;
        if ($request->has('isActive')) {
            $isActive = filter_var($request->query('isActive'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        $programs = $this->managementService->list(
            $this->resolveUser($request),
            $request->query('outletId') !== null ? (int) $request->query('outletId') : null,
            $request->query('type'),
            $isActive,
        );

        return response()->json([
            'data' => LoyaltyProgramResource::collection($programs),
        ]);
    }

    public function store(StoreLoyaltyProgramRequest $request): JsonResponse
    {
        $program = $this->managementService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty program created successfully.',
            'data' => new LoyaltyProgramResource($program),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, LoyaltyProgram $loyaltyProgram): JsonResponse
    {
        $program = $this->managementService->findScoped($this->resolveUser($request), (int) $loyaltyProgram->id);
        abort_if($program === null, Response::HTTP_NOT_FOUND, 'Loyalty program not found.');

        return response()->json([
            'data' => new LoyaltyProgramResource($program),
        ]);
    }

    public function update(UpdateLoyaltyProgramRequest $request, LoyaltyProgram $loyaltyProgram): JsonResponse
    {
        $program = $this->managementService->findScoped($this->resolveUser($request), (int) $loyaltyProgram->id);
        abort_if($program === null, Response::HTTP_NOT_FOUND, 'Loyalty program not found.');

        $updated = $this->managementService->update(
            $this->resolveUser($request),
            $program,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty program updated successfully.',
            'data' => new LoyaltyProgramResource($updated),
        ]);
    }

    public function setActivation(SetLoyaltyProgramActivationRequest $request, LoyaltyProgram $loyaltyProgram): JsonResponse
    {
        $program = $this->managementService->findScoped($this->resolveUser($request), (int) $loyaltyProgram->id);
        abort_if($program === null, Response::HTTP_NOT_FOUND, 'Loyalty program not found.');

        $updated = $this->managementService->setActive(
            $this->resolveUser($request),
            $program,
            (bool) $request->validated('isActive'),
        );

        return response()->json([
            'message' => 'Loyalty program activation updated.',
            'data' => new LoyaltyProgramResource($updated),
        ]);
    }

    public function resolveActive(Request $request): JsonResponse
    {
        $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'string'],
        ]);

        $program = $this->managementService->resolveActiveForOutlet(
            (int) $request->query('outletId'),
            (string) $request->query('type'),
        );

        return response()->json([
            'data' => $program !== null ? new LoyaltyProgramResource($program) : null,
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use App\Modules\LoyaltyEngine\Http\Requests\StoreLoyaltyProgramRuleRequest;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateLoyaltyProgramRuleRequest;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyProgramRuleResource;
use App\Modules\LoyaltyEngine\Services\LoyaltyProgramRuleManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoyaltyProgramRuleController extends Controller
{
    public function __construct(
        private readonly LoyaltyProgramRuleManagementService $ruleManagementService,
    ) {}

    public function index(Request $request, LoyaltyProgram $loyaltyProgram): JsonResponse
    {
        $rules = $this->ruleManagementService->listByProgram(
            $this->resolveUser($request),
            (int) $loyaltyProgram->id,
        );

        return response()->json([
            'data' => LoyaltyProgramRuleResource::collection($rules),
        ]);
    }

    public function store(StoreLoyaltyProgramRuleRequest $request, LoyaltyProgram $loyaltyProgram): JsonResponse
    {
        $rule = $this->ruleManagementService->create(
            $this->resolveUser($request),
            (int) $loyaltyProgram->id,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty program rule created successfully.',
            'data' => new LoyaltyProgramRuleResource($rule),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateLoyaltyProgramRuleRequest $request, LoyaltyProgramRule $loyaltyProgramRule): JsonResponse
    {
        $updated = $this->ruleManagementService->update(
            $this->resolveUser($request),
            $loyaltyProgramRule,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty program rule updated successfully.',
            'data' => new LoyaltyProgramRuleResource($updated),
        ]);
    }

    public function destroy(Request $request, LoyaltyProgramRule $loyaltyProgramRule): JsonResponse
    {
        $this->ruleManagementService->delete($this->resolveUser($request), $loyaltyProgramRule);

        return response()->json([
            'message' => 'Loyalty program rule deleted successfully.',
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

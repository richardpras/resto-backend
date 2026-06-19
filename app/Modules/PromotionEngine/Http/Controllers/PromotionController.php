<?php

namespace App\Modules\PromotionEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\PromotionEngine\Domain\Promotion;
use App\Modules\PromotionEngine\Http\Requests\EvaluatePromotionRequest;
use App\Modules\PromotionEngine\Http\Requests\SetPromotionActivationRequest;
use App\Modules\PromotionEngine\Http\Requests\StorePromotionRequest;
use App\Modules\PromotionEngine\Http\Requests\UpdatePromotionRequest;
use App\Modules\PromotionEngine\Http\Resources\PromotionResource;
use App\Modules\PromotionEngine\Services\PromotionManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PromotionController extends Controller
{
    public function __construct(
        private readonly PromotionManagementService $promotionService,
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

        $promotions = $this->promotionService->list(
            $this->resolveUser($request),
            (int) $request->query('outletId'),
            $isActive,
        );

        return response()->json([
            'data' => PromotionResource::collection($promotions),
        ]);
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $promotion = $this->promotionService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Promotion created successfully.',
            'data' => new PromotionResource($promotion),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, Promotion $promotion): JsonResponse
    {
        $scoped = $this->promotionService->findScoped($this->resolveUser($request), (int) $promotion->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Promotion not found.');

        return response()->json([
            'data' => new PromotionResource($scoped),
        ]);
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $scoped = $this->promotionService->findScoped($this->resolveUser($request), (int) $promotion->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Promotion not found.');

        $updated = $this->promotionService->update(
            $this->resolveUser($request),
            $scoped,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Promotion updated successfully.',
            'data' => new PromotionResource($updated),
        ]);
    }

    public function setActivation(SetPromotionActivationRequest $request, Promotion $promotion): JsonResponse
    {
        $scoped = $this->promotionService->findScoped($this->resolveUser($request), (int) $promotion->id);
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Promotion not found.');

        $updated = $this->promotionService->setActive(
            $this->resolveUser($request),
            $scoped,
            (bool) $request->validated('isActive'),
        );

        return response()->json([
            'message' => 'Promotion activation updated successfully.',
            'data' => new PromotionResource($updated),
        ]);
    }

    public function evaluate(EvaluatePromotionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $items = array_map(
            static fn (array $item): array => [
                'id' => (string) $item['id'],
                'name' => isset($item['name']) ? (string) $item['name'] : '',
                'price' => (float) $item['price'],
                'qty' => (float) $item['qty'],
                'category' => isset($item['category']) ? (string) $item['category'] : null,
            ],
            $validated['items'],
        );

        $result = $this->promotionService->evaluateForOutlet(
            $this->resolveUser($request),
            (int) $validated['outletId'],
            $items,
            (float) $validated['subtotal'],
        );

        return response()->json($result);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

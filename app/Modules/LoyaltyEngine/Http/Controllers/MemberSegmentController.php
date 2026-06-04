<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Modules\LoyaltyEngine\Http\Requests\SetMemberSegmentActivationRequest;
use App\Modules\LoyaltyEngine\Http\Requests\StoreMemberSegmentRequest;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateMemberSegmentRequest;
use App\Modules\LoyaltyEngine\Http\Resources\MemberSegmentPreviewMemberResource;
use App\Modules\LoyaltyEngine\Http\Resources\MemberSegmentResource;
use App\Modules\LoyaltyEngine\Services\MemberSegmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MemberSegmentController extends Controller
{
    public function __construct(
        private readonly MemberSegmentService $segmentService,
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

        $segments = $this->segmentService->list(
            $this->resolveUser($request),
            (int) $request->query('outletId'),
            $isActive,
        );

        return response()->json([
            'data' => MemberSegmentResource::collection($segments),
        ]);
    }

    public function store(StoreMemberSegmentRequest $request): JsonResponse
    {
        $segment = $this->segmentService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Member segment created successfully.',
            'data' => new MemberSegmentResource($segment),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, MemberSegment $memberSegment): JsonResponse
    {
        $segment = $this->segmentService->findScoped($this->resolveUser($request), (int) $memberSegment->id);
        abort_if($segment === null, Response::HTTP_NOT_FOUND, 'Member segment not found.');

        return response()->json([
            'data' => new MemberSegmentResource($segment),
        ]);
    }

    public function update(UpdateMemberSegmentRequest $request, MemberSegment $memberSegment): JsonResponse
    {
        $segment = $this->segmentService->findScoped($this->resolveUser($request), (int) $memberSegment->id);
        abort_if($segment === null, Response::HTTP_NOT_FOUND, 'Member segment not found.');

        $updated = $this->segmentService->update(
            $this->resolveUser($request),
            $segment,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Member segment updated successfully.',
            'data' => new MemberSegmentResource($updated),
        ]);
    }

    public function setActivation(SetMemberSegmentActivationRequest $request, MemberSegment $memberSegment): JsonResponse
    {
        $segment = $this->segmentService->findScoped($this->resolveUser($request), (int) $memberSegment->id);
        abort_if($segment === null, Response::HTTP_NOT_FOUND, 'Member segment not found.');

        $updated = $this->segmentService->setActive(
            $this->resolveUser($request),
            $segment,
            (bool) $request->validated('isActive'),
        );

        return response()->json([
            'message' => 'Member segment activation updated.',
            'data' => new MemberSegmentResource($updated),
        ]);
    }

    public function preview(Request $request, MemberSegment $memberSegment): JsonResponse
    {
        $segment = $this->segmentService->findScoped($this->resolveUser($request), (int) $memberSegment->id);
        abort_if($segment === null, Response::HTTP_NOT_FOUND, 'Member segment not found.');

        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $result = $this->segmentService->preview(
            $segment,
            (int) ($request->query('limit') ?? 50),
        );

        return response()->json([
            'data' => [
                'count' => $result['count'],
                'members' => MemberSegmentPreviewMemberResource::collection($result['members']),
            ],
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

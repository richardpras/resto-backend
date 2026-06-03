<?php

namespace App\Modules\Members\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyRewardRedemptionResource;
use App\Modules\LoyaltyEngine\Services\LoyaltyRedeemService;
use App\Modules\LoyaltyEngine\Services\LoyaltyRewardRedemptionService;
use App\Modules\Members\Http\Requests\QuickStoreMemberRequest;
use App\Modules\Members\Http\Requests\RedeemMemberPointsRequest;
use App\Modules\Members\Http\Requests\RedeemMemberRewardRequest;
use App\Modules\Members\Http\Requests\SearchMembersRequest;
use App\Modules\Members\Http\Requests\StoreMemberRequest;
use App\Modules\Members\Http\Requests\UpdateMemberRequest;
use App\Modules\Members\Http\Resources\MemberProfileResource;
use App\Modules\Members\Http\Resources\MemberResource;
use App\Modules\Members\Services\MemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MemberController extends Controller
{
    public function __construct(
        private readonly MemberService $memberService,
        private readonly LoyaltyRedeemService $loyaltyRedeemService,
        private readonly LoyaltyRewardRedemptionService $loyaltyRewardRedemptionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;
        $members = $this->memberService->listForOutlet(
            $this->resolveAuthenticatedUser($request),
            $outletId,
        );

        return response()->json([
            'data' => MemberResource::collection($members),
        ]);
    }

    public function search(SearchMembersRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $members = $this->memberService->search(
            $this->resolveAuthenticatedUser($request),
            (int) $validated['outletId'],
            (string) ($validated['q'] ?? ''),
            (int) ($validated['limit'] ?? 20),
        );

        return response()->json([
            'data' => MemberResource::collection($members),
        ]);
    }

    public function profile(Request $request, Member $member): JsonResponse
    {
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;
        $payload = $this->memberService->profile(
            $this->resolveAuthenticatedUser($request),
            (int) $member->id,
            $outletId,
        );

        return response()->json([
            'data' => new MemberProfileResource($payload),
        ]);
    }

    public function redeemReward(RedeemMemberRewardRequest $request, Member $member): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->loyaltyRewardRedemptionService->redeemReward(
            $this->resolveAuthenticatedUser($request),
            (int) $member->id,
            (int) $validated['outletId'],
            (int) $validated['rewardId'],
            isset($validated['notes']) ? (string) $validated['notes'] : null,
        );

        return response()->json([
            'message' => 'Reward redeemed successfully.',
            'data' => [
                'redemptionId' => (string) $result['redemptionId'],
                'rewardName' => $result['rewardName'],
                'pointsSpent' => $result['pointsSpent'],
                'currentBalance' => $result['currentBalance'],
                'status' => $result['status'],
            ],
        ], Response::HTTP_CREATED);
    }

    public function redemptions(Request $request, Member $member): JsonResponse
    {
        $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
        ]);

        $rows = $this->loyaltyRewardRedemptionService->listForMember(
            $this->resolveAuthenticatedUser($request),
            (int) $member->id,
            (int) $request->query('outletId'),
        );

        return response()->json([
            'data' => LoyaltyRewardRedemptionResource::collection($rows),
        ]);
    }

    public function redeem(RedeemMemberPointsRequest $request, Member $member): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->loyaltyRedeemService->redeemMemberPoints(
            $this->resolveAuthenticatedUser($request),
            (int) $member->id,
            (int) $validated['outletId'],
            (int) $validated['points'],
            isset($validated['description']) ? (string) $validated['description'] : null,
        );

        return response()->json([
            'message' => 'Loyalty points redeemed successfully.',
            'data' => [
                'memberId' => (string) $result['memberId'],
                'redeemedPoints' => $result['redeemedPoints'],
                'currentBalance' => $result['currentBalance'],
            ],
        ]);
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $member = $this->memberService->create(
            $this->resolveAuthenticatedUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Member created successfully.',
            'data' => new MemberResource($member),
        ], Response::HTTP_CREATED);
    }

    public function quickStore(QuickStoreMemberRequest $request): JsonResponse
    {
        $member = $this->memberService->create(
            $this->resolveAuthenticatedUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Member created successfully.',
            'data' => new MemberResource($member),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateMemberRequest $request, Member $member): JsonResponse
    {
        $updated = $this->memberService->update($member, $request->validated());

        return response()->json([
            'message' => 'Member updated successfully.',
            'data' => new MemberResource($updated),
        ]);
    }

    public function updateStatus(Member $member): JsonResponse
    {
        $updated = $this->memberService->toggleActive($member);

        return response()->json([
            'message' => 'Status updated.',
            'data' => new MemberResource($updated),
        ]);
    }

    public function destroy(Member $member): JsonResponse
    {
        $member->delete();

        return response()->json([
            'message' => 'Member deleted successfully.',
        ]);
    }

    private function resolveAuthenticatedUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateMemberVoucherStatusRequest;
use App\Modules\LoyaltyEngine\Http\Resources\MemberVoucherResource;
use App\Modules\LoyaltyEngine\Services\MemberVoucherService;
use App\Modules\Members\Services\MemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MemberVoucherController extends Controller
{
    public function __construct(
        private readonly MemberVoucherService $memberVoucherService,
        private readonly MemberService $memberService,
    ) {}

    public function indexForMember(Request $request, Member $member): JsonResponse
    {
        $request->validate([
            'outletId' => ['nullable', 'integer', 'min:1'],
        ]);

        $scoped = $this->memberService->findForOutlet(
            $this->resolveUser($request),
            (int) $member->id,
            $request->query('outletId') !== null ? (int) $request->query('outletId') : null,
        );
        abort_if($scoped === null, Response::HTTP_NOT_FOUND, 'Member not found.');

        $vouchers = $this->memberVoucherService->listForMember(
            $this->resolveUser($request),
            $scoped,
            $request->query('outletId') !== null ? (int) $request->query('outletId') : null,
        );

        return response()->json([
            'data' => MemberVoucherResource::collection($vouchers),
        ]);
    }

    public function show(Request $request, MemberVoucher $memberVoucher): JsonResponse
    {
        $row = $this->memberVoucherService->findScoped($this->resolveUser($request), (int) $memberVoucher->id);
        abort_if($row === null, Response::HTTP_NOT_FOUND, 'Member voucher not found.');

        return response()->json([
            'data' => new MemberVoucherResource($row),
        ]);
    }

    public function updateStatus(UpdateMemberVoucherStatusRequest $request, MemberVoucher $memberVoucher): JsonResponse
    {
        $row = $this->memberVoucherService->findScoped($this->resolveUser($request), (int) $memberVoucher->id);
        abort_if($row === null, Response::HTTP_NOT_FOUND, 'Member voucher not found.');

        $updated = $this->memberVoucherService->updateStatus(
            $this->resolveUser($request),
            $row,
            (string) $request->validated('status'),
        );

        return response()->json([
            'message' => 'Member voucher status updated.',
            'data' => new MemberVoucherResource($updated),
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

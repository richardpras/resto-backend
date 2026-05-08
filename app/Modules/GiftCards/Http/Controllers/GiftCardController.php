<?php

namespace App\Modules\GiftCards\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\GiftCards\Http\Requests\CheckGiftCardRequest;
use App\Modules\GiftCards\Http\Requests\IssueGiftCardRequest;
use App\Modules\GiftCards\Http\Requests\RedeemGiftCardRequest;
use App\Modules\GiftCards\Http\Requests\SettleGiftCardRedemptionRequest;
use App\Modules\GiftCards\Http\Resources\GiftCardIssuanceResource;
use App\Modules\GiftCards\Http\Resources\GiftCardRedemptionSettlementResource;
use App\Modules\GiftCards\Services\GiftCardService;
use App\Modules\GiftCards\Services\GiftCardSettlementHookService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class GiftCardController extends Controller
{
    public function __construct(
        private readonly GiftCardService $giftCardService,
        private readonly GiftCardSettlementHookService $settlementHookService,
    ) {}

    public function issue(IssueGiftCardRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $result = $this->giftCardService->issue($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Gift card/store credit issued successfully.',
            'data' => [
                'idempotent' => $result['idempotent'],
                'issuance' => (new GiftCardIssuanceResource($result['issuance']))->resolve(),
            ],
            'meta' => null,
        ], Response::HTTP_CREATED);
    }

    public function check(CheckGiftCardRequest $request, string $code): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $issuance = $this->giftCardService->check($user, (int) $request->validated('outletId'), $code);

        return response()->json([
            'success' => true,
            'message' => 'Gift card/store credit status retrieved successfully.',
            'data' => (new GiftCardIssuanceResource($issuance))->resolve(),
            'meta' => null,
        ]);
    }

    public function redeem(RedeemGiftCardRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $result = $this->giftCardService->redeem($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Gift card/store credit redeemed successfully.',
            'data' => [
                'idempotent' => $result['idempotent'],
                'issuance' => (new GiftCardIssuanceResource($result['issuance']))->resolve(),
                'settlement' => (new GiftCardRedemptionSettlementResource($result['settlement']))->resolve(),
            ],
            'meta' => null,
        ], Response::HTTP_CREATED);
    }

    public function settle(SettleGiftCardRedemptionRequest $request): JsonResponse
    {
        $result = $this->settlementHookService->settle($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Gift card/store credit settlements processed successfully.',
            'data' => $result,
            'meta' => null,
        ]);
    }
}

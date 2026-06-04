<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Modules\LoyaltyEngine\Http\Requests\SetLoyaltyVoucherActivationRequest;
use App\Modules\LoyaltyEngine\Http\Requests\StoreLoyaltyVoucherRequest;
use App\Modules\LoyaltyEngine\Http\Requests\UpdateLoyaltyVoucherRequest;
use App\Modules\LoyaltyEngine\Http\Resources\LoyaltyVoucherResource;
use App\Modules\LoyaltyEngine\Services\LoyaltyVoucherService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoyaltyVoucherController extends Controller
{
    public function __construct(
        private readonly LoyaltyVoucherService $voucherService,
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

        $vouchers = $this->voucherService->list(
            $this->resolveUser($request),
            (int) $request->query('outletId'),
            $isActive,
        );

        return response()->json([
            'data' => LoyaltyVoucherResource::collection($vouchers),
        ]);
    }

    public function store(StoreLoyaltyVoucherRequest $request): JsonResponse
    {
        $voucher = $this->voucherService->create(
            $this->resolveUser($request),
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty voucher created successfully.',
            'data' => new LoyaltyVoucherResource($voucher),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, LoyaltyVoucher $loyaltyVoucher): JsonResponse
    {
        $voucher = $this->voucherService->findScoped($this->resolveUser($request), (int) $loyaltyVoucher->id);
        abort_if($voucher === null, Response::HTTP_NOT_FOUND, 'Loyalty voucher not found.');

        return response()->json([
            'data' => new LoyaltyVoucherResource($voucher),
        ]);
    }

    public function update(UpdateLoyaltyVoucherRequest $request, LoyaltyVoucher $loyaltyVoucher): JsonResponse
    {
        $voucher = $this->voucherService->findScoped($this->resolveUser($request), (int) $loyaltyVoucher->id);
        abort_if($voucher === null, Response::HTTP_NOT_FOUND, 'Loyalty voucher not found.');

        $updated = $this->voucherService->update(
            $this->resolveUser($request),
            $voucher,
            $request->validated(),
        );

        return response()->json([
            'message' => 'Loyalty voucher updated successfully.',
            'data' => new LoyaltyVoucherResource($updated),
        ]);
    }

    public function setActivation(SetLoyaltyVoucherActivationRequest $request, LoyaltyVoucher $loyaltyVoucher): JsonResponse
    {
        $voucher = $this->voucherService->findScoped($this->resolveUser($request), (int) $loyaltyVoucher->id);
        abort_if($voucher === null, Response::HTTP_NOT_FOUND, 'Loyalty voucher not found.');

        $updated = $this->voucherService->setActive(
            $this->resolveUser($request),
            $voucher,
            (bool) $request->validated('isActive'),
        );

        return response()->json([
            'message' => 'Loyalty voucher activation updated.',
            'data' => new LoyaltyVoucherResource($updated),
        ]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

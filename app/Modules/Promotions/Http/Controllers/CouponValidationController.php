<?php

namespace App\Modules\Promotions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Promotions\Http\Requests\ValidateCouponRequest;
use App\Modules\Promotions\Services\CouponValidationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CouponValidationController extends Controller
{
    public function __construct(
        private readonly CouponValidationService $couponValidationService,
    ) {}

    public function validateCoupon(ValidateCouponRequest $request): JsonResponse
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');
        $result = $this->couponValidationService->validate($user, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Coupon validation completed successfully.',
            'data' => $result,
            'meta' => null,
        ]);
    }
}

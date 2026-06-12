<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\QrOrderLifecycleService;
use App\Modules\Orders\Services\QrOrderPublicLookupService;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class QrOrderPublicController extends Controller
{
    public function __construct(
        private readonly QrOrderPublicLookupService $lookupService,
        private readonly QrOrderLifecycleService $lifecycleService,
    ) {}

    public function show(string $orderCode): JsonResponse
    {
        try {
            $data = $this->lookupService->findByOrderCode($orderCode);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Order not found or expired.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $data,
        ]);
    }

    public function approveAdjustments(string $orderCode): JsonResponse
    {
        try {
            $request = $this->lifecycleService->approveAdjustments($orderCode);
            $data = $this->lookupService->findByOrderCode((string) $request->request_code);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?? 'Unable to approve adjustments.',
                'errors' => $exception->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Order not found or expired.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'message' => 'Adjustments approved.',
            'data' => $data,
        ]);
    }
}

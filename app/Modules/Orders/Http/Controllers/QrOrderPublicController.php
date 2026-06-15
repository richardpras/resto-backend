<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\QrOrderLifecycleService;
use App\Modules\Orders\Services\QrOrderPublicLookupService;
use App\Support\AppLocale;
use Illuminate\Http\Request;
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

    public function show(Request $request, string $orderCode): JsonResponse
    {
        $locale = AppLocale::fromRequest($request);

        try {
            $data = $this->lookupService->findByOrderCode($orderCode, $locale);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => trans('qr.public.not_found', [], $locale),
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $data,
        ]);
    }

    public function approveAdjustments(Request $request, string $orderCode): JsonResponse
    {
        $locale = AppLocale::fromRequest($request);

        try {
            $qrRequest = $this->lifecycleService->approveAdjustments($orderCode);
            $data = $this->lookupService->findByOrderCode((string) $qrRequest->request_code, $locale);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first()
                    ?? trans('qr.public.approve_failed', [], $locale),
                'errors' => $exception->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => trans('qr.public.not_found', [], $locale),
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'message' => trans('qr.public.approved', [], $locale),
            'data' => $data,
        ]);
    }
}

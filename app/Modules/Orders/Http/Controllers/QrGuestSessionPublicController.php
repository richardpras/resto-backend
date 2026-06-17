<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\QrGuestSessionOrdersService;
use App\Support\AppLocale;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QrGuestSessionPublicController extends Controller
{
    public function __construct(
        private readonly QrGuestSessionOrdersService $guestSessionOrdersService,
    ) {}

    public function orders(Request $request, string $guestSessionToken): JsonResponse
    {
        $locale = AppLocale::fromRequest($request);

        try {
            $orders = $this->guestSessionOrdersService->listForToken($guestSessionToken, $locale);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Guest session not found or expired.',
                'code' => 'guest_session_invalid',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $orders,
        ]);
    }
}

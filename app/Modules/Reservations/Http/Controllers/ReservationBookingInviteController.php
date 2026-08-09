<?php

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Services\ReservationBookingInviteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationBookingInviteController extends Controller
{
    public function __construct(
        private readonly ReservationBookingInviteService $inviteService,
    ) {}

    public function store(int $outletId): JsonResponse
    {
        try {
            $payload = $this->inviteService->create(request()->user(), $outletId);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Outlet not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'message' => 'Reservation invite link created.',
            'data' => $payload,
        ], Response::HTTP_CREATED);
    }
}

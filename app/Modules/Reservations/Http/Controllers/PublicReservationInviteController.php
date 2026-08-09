<?php

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Resources\PublicMenuItemResource;
use App\Modules\Menu\Services\PublicOutletMenuService;
use App\Modules\Reservations\Http\Requests\StorePublicReservationRequest;
use App\Modules\Reservations\Http\Resources\ReservationResource;
use App\Modules\Reservations\Services\PublicReservationService;
use App\Modules\Reservations\Services\ReservationBookingInviteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicReservationInviteController extends Controller
{
    public function __construct(
        private readonly ReservationBookingInviteService $inviteService,
        private readonly PublicReservationService $publicReservationService,
        private readonly PublicOutletMenuService $publicOutletMenuService,
    ) {}

    public function show(string $token): JsonResponse
    {
        try {
            $context = $this->publicReservationService->showInviteContext($token);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Reservation invite is invalid or expired.',
                'code' => 'reservation_invite_unavailable',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($context);
    }

    public function menu(string $token): JsonResponse
    {
        try {
            $settings = $this->inviteService->resolveSettingsForInvite($token);
            $outlet = $settings->outlet;
            if ($outlet === null) {
                throw new ModelNotFoundException;
            }
            $items = $this->publicOutletMenuService->listForOutlet($outlet);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Reservation invite is invalid or expired.',
                'code' => 'reservation_invite_unavailable',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => PublicMenuItemResource::collection($items),
        ]);
    }

    public function store(StorePublicReservationRequest $request, string $token): JsonResponse
    {
        try {
            $reservation = $this->publicReservationService->createFromInvite($token, $request->validated());
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Reservation invite is invalid or expired.',
                'code' => 'reservation_invite_unavailable',
            ], Response::HTTP_NOT_FOUND);
        }

        $reservation->load(['linkedOrder.items', 'depositProofs']);

        return response()->json([
            'message' => 'Reservation submitted. Please upload deposit proof to confirm.',
            'data' => new ReservationResource($reservation),
        ], Response::HTTP_CREATED);
    }
}

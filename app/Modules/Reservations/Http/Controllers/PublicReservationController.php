<?php

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Menu\Http\Resources\PublicMenuItemResource;
use App\Modules\Menu\Services\PublicOutletMenuService;
use App\Modules\Reservations\Http\Requests\StorePublicReservationRequest;
use App\Modules\Reservations\Http\Requests\SubmitReservationDepositProofRequest;
use App\Modules\Reservations\Http\Resources\ReservationResource;
use App\Modules\Reservations\Services\PublicReservationService;
use App\Modules\Reservations\Services\PublicReservationPdfService;
use App\Modules\Reservations\Services\ReservationDepositService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

class PublicReservationController extends Controller
{
    public function __construct(
        private readonly PublicReservationService $publicReservationService,
        private readonly PublicOutletMenuService $publicOutletMenuService,
        private readonly ReservationDepositService $depositService,
        private readonly PublicReservationPdfService $pdfService,
    ) {}

    public function showOutlet(string $outletSlug): JsonResponse
    {
        try {
            $context = $this->publicReservationService->showOutletContext($outletSlug);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Public reservation is not available for this outlet.',
                'code' => 'public_reservation_unavailable',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json($context);
    }

    public function menu(string $outletSlug): JsonResponse
    {
        try {
            $settings = $this->publicReservationService->resolveSettings($outletSlug);
            $outlet = $settings->outlet;
            if ($outlet === null) {
                throw new ModelNotFoundException;
            }
            $items = $this->publicOutletMenuService->listForOutlet($outlet);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Public reservation is not available for this outlet.',
                'code' => 'public_reservation_unavailable',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => PublicMenuItemResource::collection($items),
        ]);
    }

    public function store(StorePublicReservationRequest $request, string $outletSlug): JsonResponse
    {
        try {
            $reservation = $this->publicReservationService->create($outletSlug, $request->validated());
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Public reservation is not available for this outlet.',
                'code' => 'public_reservation_unavailable',
            ], Response::HTTP_NOT_FOUND);
        }

        $reservation->load(['linkedOrder.items', 'depositProofs']);

        return response()->json([
            'message' => 'Reservation submitted. Please upload deposit proof to confirm.',
            'data' => new ReservationResource($reservation),
        ], Response::HTTP_CREATED);
    }

    public function show(string $reservationCode): JsonResponse
    {
        try {
            $reservation = $this->publicReservationService->showByCode($reservationCode);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Reservation not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => new ReservationResource($reservation),
        ]);
    }

    public function pdf(string $reservationCode): HttpResponse|JsonResponse
    {
        try {
            $binary = $this->pdfService->renderByCode($reservationCode);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Reservation not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $filename = 'reservation-'.$reservationCode.'.pdf';

        return response($binary, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function submitDepositProof(
        SubmitReservationDepositProofRequest $request,
        string $reservationCode,
    ): JsonResponse {
        try {
            $reservation = $this->depositService->submitProof(
                $reservationCode,
                $request->file('proof'),
            );
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Reservation not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $reservation->load(['linkedOrder.items', 'depositProofs']);

        return response()->json([
            'message' => 'Deposit proof uploaded. Awaiting staff review.',
            'data' => new ReservationResource($reservation),
        ]);
    }
}

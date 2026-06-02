<?php

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Http\Requests\ListReservationsRequest;
use App\Modules\Reservations\Http\Requests\StoreReservationRequest;
use App\Modules\Reservations\Http\Resources\ReservationResource;
use App\Modules\Reservations\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $service,
    ) {}

    public function store(StoreReservationRequest $request): JsonResponse
    {
        $reservation = $this->service->create($request->user(), $request->validated());

        return response()->json([
            'message' => 'Reservation created successfully.',
            'data' => new ReservationResource($reservation),
        ], Response::HTTP_CREATED);
    }

    public function index(ListReservationsRequest $request): JsonResponse
    {
        $rows = $this->service->list($request->user(), $request->validated());

        return response()->json([
            'data' => ReservationResource::collection($rows),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $reservation = $this->service->show(request()->user(), $id);

        return response()->json([
            'data' => new ReservationResource($reservation),
        ]);
    }

    public function confirm(int $id): JsonResponse
    {
        $reservation = $this->service->confirm(request()->user(), $id);

        return $this->transitionResponse($reservation, 'Reservation confirmed successfully.');
    }

    public function checkIn(int $id): JsonResponse
    {
        $reservation = $this->service->checkIn(request()->user(), $id);

        return $this->transitionResponse($reservation, 'Reservation checked in successfully.');
    }

    public function seat(int $id): JsonResponse
    {
        $reservation = $this->service->seat(request()->user(), $id);

        return $this->transitionResponse($reservation, 'Reservation seated successfully.');
    }

    public function complete(int $id): JsonResponse
    {
        $reservation = $this->service->complete(request()->user(), $id);

        return $this->transitionResponse($reservation, 'Reservation completed successfully.');
    }

    public function cancel(int $id): JsonResponse
    {
        $reservation = $this->service->cancel(request()->user(), $id);

        return $this->transitionResponse($reservation, 'Reservation cancelled successfully.');
    }

    public function markNoShow(int $id): JsonResponse
    {
        $reservation = $this->service->markNoShow(request()->user(), $id);

        return $this->transitionResponse($reservation, 'Reservation marked as no show successfully.');
    }

    private function transitionResponse(mixed $reservation, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new ReservationResource($reservation),
        ]);
    }
}

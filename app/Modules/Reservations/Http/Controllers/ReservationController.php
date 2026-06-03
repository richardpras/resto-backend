<?php

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Http\Requests\AllocateReservationTableRequest;
use App\Modules\Reservations\Http\Requests\ListReservationsRequest;
use App\Modules\Reservations\Http\Requests\ReservationDashboardRequest;
use App\Modules\Reservations\Http\Requests\StoreReservationRequest;
use App\Modules\Reservations\Http\Requests\UnallocateReservationTableRequest;
use App\Modules\Reservations\Http\Resources\ReservationResource;
use App\Modules\Reservations\Http\Resources\ReservationTableAllocationResource;
use App\Modules\Reservations\Services\ReservationAllocationService;
use App\Modules\Reservations\Services\ReservationDashboardService;
use App\Modules\Reservations\Services\ReservationService;
use App\Modules\Reservations\Services\ReservationTimelineService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $service,
        private readonly ReservationAllocationService $allocationService,
        private readonly ReservationDashboardService $dashboardService,
        private readonly ReservationTimelineService $timelineService,
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

    public function dashboard(ReservationDashboardRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $payload = $this->dashboardService->dashboard(
            $request->user(),
            (int) $validated['outletId'],
            isset($validated['from']) ? (string) $validated['from'] : null,
            isset($validated['to']) ? (string) $validated['to'] : null,
        );

        return response()->json([
            'metrics' => $payload['metrics'],
            'upcomingReservations' => ReservationResource::collection($payload['upcomingReservations']),
            'activeReservations' => ReservationResource::collection($payload['activeReservations']),
            'noShowToday' => $payload['noShowToday'],
        ]);
    }

    public function timeline(int $id): JsonResponse
    {
        $events = $this->timelineService->timeline(request()->user(), $id);

        return response()->json([
            'data' => $events,
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

    public function allocateTable(AllocateReservationTableRequest $request, int $id): JsonResponse
    {
        $rows = $this->allocationService->allocateTables($request->user(), $id, $request->validated());

        return response()->json([
            'message' => 'Table(s) allocated successfully.',
            'data' => ReservationTableAllocationResource::collection($rows),
        ]);
    }

    public function unallocateTable(UnallocateReservationTableRequest $request, int $id): JsonResponse
    {
        $tableId = (int) $request->validated('tableId');
        $rows = $this->allocationService->unallocateTable($request->user(), $id, $tableId);

        return response()->json([
            'message' => 'Table unallocated successfully.',
            'data' => ReservationTableAllocationResource::collection($rows),
        ]);
    }

    public function allocatedTables(int $id): JsonResponse
    {
        $rows = $this->allocationService->listAllocatedTables(request()->user(), $id);

        return response()->json([
            'data' => ReservationTableAllocationResource::collection($rows),
        ]);
    }

    public function startService(int $id): JsonResponse
    {
        $reservation = $this->service->startService(request()->user(), $id);

        return response()->json([
            'message' => 'Service started successfully.',
            'data' => new ReservationResource($reservation),
            'linkedOrderId' => (int) $reservation->linked_order_id,
            'serviceStartedAt' => $reservation->service_started_at?->toISOString(),
        ]);
    }

    private function transitionResponse(mixed $reservation, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new ReservationResource($reservation),
        ]);
    }
}

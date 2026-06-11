<?php

namespace App\Modules\Kitchen\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Kitchen\Http\Requests\ListKitchenTicketsRequest;
use App\Modules\Kitchen\Http\Requests\UpdateKitchenTicketStatusRequest;
use App\Modules\Kitchen\Http\Resources\KitchenTicketResource;
use App\Modules\Kitchen\Services\KitchenTicketService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class KitchenTicketController extends Controller
{
    public function __construct(
        private readonly KitchenTicketService $service,
    ) {}

    public function index(ListKitchenTicketsRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $tickets = $this->service->listTickets($user, [
            'outlet_id' => $request->validated('outletId'),
            'status' => $request->validated('status'),
            'station_id' => $request->validated('stationId'),
            'station_code' => $request->validated('stationCode'),
            'per_page' => $request->validated('perPage', 20),
        ]);

        return response()->json([
            'data' => KitchenTicketResource::collection(collect($tickets->items())),
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'currentPage' => $tickets->currentPage(),
                'perPage' => $tickets->perPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
                'lastPage' => $tickets->lastPage(),
                'last_page' => $tickets->lastPage(),
            ],
        ]);
    }

    public function updateStatus(UpdateKitchenTicketStatusRequest $request, int $ticket): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $updated = $this->service->updateStatus(
            $user,
            $ticket,
            (string) $request->validated('status'),
            $request->validated('idempotencyKey') ?? $request->header('Idempotency-Key'),
            $request->validated('expectedUpdatedAt')
        );
        abort_if($updated === null, Response::HTTP_NOT_FOUND, 'Kitchen ticket not found');

        return response()->json([
            'message' => 'Kitchen ticket status updated successfully.',
            'data' => new KitchenTicketResource($updated),
        ]);
    }
}

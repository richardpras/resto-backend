<?php

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Http\Requests\ListPendingDepositsRequest;
use App\Modules\Reservations\Http\Requests\RejectReservationDepositRequest;
use App\Modules\Reservations\Http\Resources\ReservationResource;
use App\Modules\Reservations\Services\ReservationDepositService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ReservationDepositController extends Controller
{
    public function __construct(
        private readonly ReservationDepositService $depositService,
    ) {}

    public function index(ListPendingDepositsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $rows = $this->depositService->listPendingDeposits(
            $request->user(),
            (int) $validated['outletId'],
        );

        return response()->json([
            'data' => ReservationResource::collection($rows),
        ]);
    }

    public function approve(int $id): JsonResponse
    {
        $reservation = $this->depositService->approveDeposit(request()->user(), $id);
        $reservation->load(['linkedOrder.items', 'depositProofs']);

        return response()->json([
            'message' => 'Deposit approved. Reservation confirmed.',
            'data' => new ReservationResource($reservation),
        ]);
    }

    public function reject(RejectReservationDepositRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();
        $reservation = $this->depositService->rejectDeposit(
            $request->user(),
            $id,
            isset($validated['reason']) ? (string) $validated['reason'] : null,
        );
        $reservation->load(['linkedOrder.items', 'depositProofs']);

        return response()->json([
            'message' => 'Deposit rejected. Reservation cancelled.',
            'data' => new ReservationResource($reservation),
        ]);
    }

    public function proofFile(int $id, int $proofId): Response
    {
        $path = $this->depositService->proofFilePath(request()->user(), $id, $proofId);

        return response()->file($path);
    }
}

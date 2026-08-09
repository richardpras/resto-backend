<?php

namespace App\Modules\Reservations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reservations\Http\Requests\ListPendingDepositsRequest;
use App\Modules\Reservations\Http\Requests\RejectReservationDepositRequest;
use App\Modules\Reservations\Http\Requests\SubmitStaffReservationDepositProofRequest;
use App\Modules\Reservations\Http\Resources\ReservationResource;
use App\Modules\Reservations\Services\ReservationDepositService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public function submitProof(SubmitStaffReservationDepositProofRequest $request, int $id): JsonResponse
    {
        $reservation = $this->depositService->submitProofForStaff(
            $request->user(),
            $id,
            $request->file('proof'),
        );
        $reservation->load(['linkedOrder.items', 'depositProofs']);

        return response()->json([
            'message' => 'Deposit proof uploaded. Awaiting review.',
            'data' => new ReservationResource($reservation),
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

    public function proofFile(int $id, int $proofId): Response|JsonResponse
    {
        try {
            $resolved = $this->depositService->resolveProofFile(request()->user(), $id, $proofId);
        } catch (ModelNotFoundException) {
            return response()->json([
                'message' => 'Deposit proof not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $safeFilename = str_replace(['"', "\r", "\n"], '', $resolved['filename']);

        return response()->file($resolved['path'], [
            'Content-Type' => $resolved['mime'],
            'Content-Disposition' => 'attachment; filename="'.$safeFilename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Frame-Options' => 'DENY',
        ]);
    }
}

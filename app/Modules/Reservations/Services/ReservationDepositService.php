<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Reservations\Domain\ReservationDepositProof;
use App\Models\Modules\Settings\Domain\OutletReservationSetting;
use App\Models\User;
use App\Modules\Orders\Services\PaymentAllocationService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReservationDepositService
{
    public function __construct(
        private readonly ReservationPolicyService $policyService,
        private readonly ReservationRealtimePublisher $realtimePublisher,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PaymentAllocationService $paymentAllocationService,
    ) {}

    public function submitProof(string $reservationCode, UploadedFile $file): Reservation
    {
        return DB::transaction(function () use ($reservationCode, $file): Reservation {
            $reservation = Reservation::query()
                ->where('reservation_code', $reservationCode)
                ->where('source', 'public')
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservationCode]);
            }

            $fromStatus = (string) $reservation->status;
            if ($fromStatus !== 'pending_deposit') {
                throw ValidationException::withMessages([
                    'status' => ['Deposit proof can only be uploaded while reservation is pending deposit.'],
                ]);
            }

            $path = $file->store('reservation-deposits/'.(int) $reservation->outlet_id, 'local');

            ReservationDepositProof::query()->create([
                'reservation_id' => (int) $reservation->id,
                'file_path' => (string) $path,
                'original_filename' => (string) $file->getClientOriginalName(),
                'uploaded_at' => now(),
                'status' => 'pending',
            ]);

            $this->policyService->assertTransitionAllowed($fromStatus, 'deposit_submitted');
            $reservation->status = 'deposit_submitted';
            $reservation->save();

            $fresh = $reservation->fresh(['depositProofs', 'linkedOrder']) ?? $reservation;
            $this->realtimePublisher->publishStatusChanged($fresh, $fromStatus, 'deposit_submitted');

            return $fresh;
        });
    }

    /** @return Collection<int, Reservation> */
    public function listPendingDeposits(User $user, int $outletId): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        return Reservation::query()
            ->where('outlet_id', $outletId)
            ->whereIn('status', ['pending_deposit', 'deposit_submitted'])
            ->with(['depositProofs', 'linkedOrder.items'])
            ->orderByDesc('updated_at')
            ->get();
    }

    public function approveDeposit(User $user, int $reservationId): Reservation
    {
        return DB::transaction(function () use ($user, $reservationId): Reservation {
            $reservation = $this->findScopedOrFail($user, $reservationId, true);
            $fromStatus = (string) $reservation->status;

            if ($fromStatus !== 'deposit_submitted') {
                throw ValidationException::withMessages([
                    'status' => ['Only submitted deposits can be approved.'],
                ]);
            }

            $amount = (float) $reservation->required_deposit_amount;
            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'requiredDepositAmount' => ['Required deposit amount is missing.'],
                ]);
            }

            $this->policyService->assertTransitionAllowed($fromStatus, 'confirmed');

            if ($reservation->linked_order_id !== null) {
                $this->paymentAllocationService->addPayments($user, (int) $reservation->linked_order_id, [[
                    'method' => 'reservation_deposit',
                    'amount' => $amount,
                ]]);
            }

            ReservationDepositProof::query()
                ->where('reservation_id', (int) $reservation->id)
                ->where('status', 'pending')
                ->update(['status' => 'approved']);

            $reservation->status = 'confirmed';
            $reservation->confirmed_at = now();
            $reservation->approved_deposit_amount = $amount;
            $reservation->deposit_reviewed_at = now();
            $reservation->deposit_reviewed_by = (int) $user->id;
            $reservation->save();

            $fresh = $reservation->fresh(['depositProofs', 'linkedOrder']) ?? $reservation;
            $this->realtimePublisher->publishStatusChanged($fresh, $fromStatus, 'confirmed');

            return $fresh;
        });
    }

    public function rejectDeposit(User $user, int $reservationId, ?string $reason): Reservation
    {
        return DB::transaction(function () use ($user, $reservationId, $reason): Reservation {
            $reservation = $this->findScopedOrFail($user, $reservationId, true);
            $fromStatus = (string) $reservation->status;

            if (! in_array($fromStatus, ['pending_deposit', 'deposit_submitted'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Deposit can only be rejected for pending public reservations.'],
                ]);
            }

            $this->policyService->assertTransitionAllowed($fromStatus, 'cancelled');

            ReservationDepositProof::query()
                ->where('reservation_id', (int) $reservation->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            $reservation->status = 'cancelled';
            $reservation->cancelled_at = now();
            $reservation->deposit_reviewed_at = now();
            $reservation->deposit_reviewed_by = (int) $user->id;
            $reservation->deposit_rejection_reason = $reason;
            $reservation->save();

            if ($reservation->linked_order_id !== null) {
                $order = $reservation->linkedOrder;
                if ($order !== null && (string) $order->status !== 'cancelled') {
                    $order->status = 'cancelled';
                    $order->save();
                }
            }

            $fresh = $reservation->fresh(['depositProofs', 'linkedOrder']) ?? $reservation;
            $this->realtimePublisher->publishStatusChanged($fresh, $fromStatus, 'cancelled');

            return $fresh;
        });
    }

    public function proofFilePath(User $user, int $reservationId, int $proofId): string
    {
        $reservation = $this->findScopedOrFail($user, $reservationId);
        $proof = ReservationDepositProof::query()
            ->where('reservation_id', (int) $reservation->id)
            ->whereKey($proofId)
            ->first();

        if ($proof === null) {
            throw (new ModelNotFoundException)->setModel(ReservationDepositProof::class, [(string) $proofId]);
        }

        if (! Storage::disk('local')->exists((string) $proof->file_path)) {
            throw (new ModelNotFoundException)->setModel(ReservationDepositProof::class, [(string) $proofId]);
        }

        return Storage::disk('local')->path((string) $proof->file_path);
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    private function findScopedOrFail(User $user, int $reservationId, bool $lockForUpdate = false): Reservation
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $query = Reservation::query()
            ->whereIn('outlet_id', $allowed === [] ? [-1] : $allowed)
            ->whereKey($reservationId);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $reservation = $query->first();
        if ($reservation === null) {
            throw (new ModelNotFoundException)->setModel(Reservation::class, [(string) $reservationId]);
        }

        return $reservation;
    }
}

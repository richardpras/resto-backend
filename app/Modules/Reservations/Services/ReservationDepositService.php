<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Reservations\Domain\ReservationDepositProof;
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

            return $this->attachProofAndSubmit($reservation, $file);
        });
    }

    public function submitProofForStaff(User $user, int $reservationId, UploadedFile $file): Reservation
    {
        return DB::transaction(function () use ($user, $reservationId, $file): Reservation {
            $reservation = $this->findScopedOrFail($user, $reservationId, true);

            return $this->attachProofAndSubmit($reservation, $file);
        });
    }

    private function attachProofAndSubmit(Reservation $reservation, UploadedFile $file): Reservation
    {
        $fromStatus = (string) $reservation->status;
        if ($fromStatus !== 'pending_deposit') {
            throw ValidationException::withMessages([
                'status' => ['Deposit proof can only be uploaded while reservation is pending deposit.'],
            ]);
        }

        $this->assertSafeProofUpload($file);

        $outletId = (int) $reservation->outlet_id;
        $directory = 'reservation-deposits/'.$outletId;
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'));
        $allowedExtensions = array_map('strtolower', (array) config('reservations.deposit_proof_mimes', ['jpg', 'jpeg', 'png', 'webp', 'pdf']));
        if (! in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'proof' => ['Unsupported deposit proof file type.'],
            ]);
        }

        $storedName = bin2hex(random_bytes(16)).'.'.$extension;
        $path = $file->storeAs($directory, $storedName, 'local');
        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'proof' => ['Failed to store deposit proof.'],
            ]);
        }

        ReservationDepositProof::query()->create([
            'reservation_id' => (int) $reservation->id,
            'file_path' => $path,
            'original_filename' => $this->sanitizeOriginalFilename($file, $extension),
            'uploaded_at' => now(),
            'status' => 'pending',
        ]);

        $this->policyService->assertTransitionAllowed($fromStatus, 'deposit_submitted');
        $reservation->status = 'deposit_submitted';
        $reservation->save();

        $fresh = $reservation->fresh(['depositProofs', 'linkedOrder']) ?? $reservation;
        $this->realtimePublisher->publishStatusChanged($fresh, $fromStatus, 'deposit_submitted');

        return $fresh;
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

    /**
     * @return array{path: string, mime: string, filename: string}
     */
    public function resolveProofFile(User $user, int $reservationId, int $proofId): array
    {
        $reservation = $this->findScopedOrFail($user, $reservationId);
        $proof = ReservationDepositProof::query()
            ->where('reservation_id', (int) $reservation->id)
            ->whereKey($proofId)
            ->first();

        if ($proof === null) {
            throw (new ModelNotFoundException)->setModel(ReservationDepositProof::class, [(string) $proofId]);
        }

        $relative = (string) $proof->file_path;
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            throw (new ModelNotFoundException)->setModel(ReservationDepositProof::class, [(string) $proofId]);
        }

        $expectedPrefix = 'reservation-deposits/'.(int) $reservation->outlet_id.'/';
        if (! str_starts_with($relative, $expectedPrefix)) {
            throw (new ModelNotFoundException)->setModel(ReservationDepositProof::class, [(string) $proofId]);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($relative)) {
            throw (new ModelNotFoundException)->setModel(ReservationDepositProof::class, [(string) $proofId]);
        }

        $absolute = $disk->path($relative);
        $root = realpath($disk->path('reservation-deposits'));
        $real = realpath($absolute);
        if ($root === false || $real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            throw (new ModelNotFoundException)->setModel(ReservationDepositProof::class, [(string) $proofId]);
        }

        $mime = $this->detectAllowedMime($real);
        if ($mime === null) {
            throw (new ModelNotFoundException)->setModel(ReservationDepositProof::class, [(string) $proofId]);
        }

        return [
            'path' => $real,
            'mime' => $mime,
            'filename' => $this->sanitizeDownloadFilename((string) $proof->original_filename, $mime),
        ];
    }

    /** @deprecated Use resolveProofFile() */
    public function proofFilePath(User $user, int $reservationId, int $proofId): string
    {
        return $this->resolveProofFile($user, $reservationId, $proofId)['path'];
    }

    private function assertSafeProofUpload(UploadedFile $file): void
    {
        $allowedMimes = [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
        ];

        $detected = null;
        $realPath = $file->getRealPath();
        if (is_string($realPath) && $realPath !== '' && is_file($realPath)) {
            $detected = $this->detectAllowedMime($realPath);
        }

        $clientMime = strtolower((string) ($file->getMimeType() ?: ''));
        if ($detected === null || ! in_array($detected, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'proof' => ['Deposit proof must be a valid JPG, PNG, WEBP, or PDF file.'],
            ]);
        }

        if ($clientMime !== '' && ! in_array($clientMime, $allowedMimes, true) && $clientMime !== $detected) {
            // Tolerate browser quirks only when detected content is already allowlisted.
            if (! str_starts_with($clientMime, 'image/') && $clientMime !== 'application/pdf') {
                throw ValidationException::withMessages([
                    'proof' => ['Deposit proof content type is not allowed.'],
                ]);
            }
        }
    }

    private function detectAllowedMime(string $absolutePath): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) $finfo->file($absolutePath));

        return match ($detected) {
            'image/jpeg', 'image/pjpeg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
            'application/pdf' => 'application/pdf',
            default => null,
        };
    }

    private function sanitizeOriginalFilename(UploadedFile $file, string $extension): string
    {
        $raw = str_replace(["\0", '\\', '/'], '', (string) $file->getClientOriginalName());
        $base = basename($raw);
        $base = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $base) ?? 'proof';
        $base = trim($base, '._ ');
        if ($base === '' || $base === '.'.$extension) {
            $base = 'proof.'.$extension;
        }
        if (! str_ends_with(strtolower($base), '.'.strtolower($extension))) {
            $base .= '.'.$extension;
        }

        return mb_substr($base, 0, 180);
    }

    private function sanitizeDownloadFilename(string $original, string $mime): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };

        $base = basename(str_replace(["\0", '\\', '/'], '', $original));
        $base = preg_replace('/[\r\n\t";]+/', '', $base) ?? '';
        $base = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $base) ?? '';
        $base = trim($base, '._ ');
        if ($base === '') {
            $base = 'deposit-proof.'.$extension;
        }
        if (! str_ends_with(strtolower($base), '.'.$extension)) {
            $base .= '.'.$extension;
        }

        return mb_substr($base, 0, 180);
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

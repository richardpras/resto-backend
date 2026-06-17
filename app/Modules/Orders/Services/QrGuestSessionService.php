<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\QrGuestSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Settings\Services\QrOrderingSettingsService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QrGuestSessionService
{
  private const BROWSING_TTL_HOURS = 8;

  public function __construct(
    private readonly QrOrderingSettingsService $qrOrderingSettingsService,
  ) {}

  /**
   * @return array{session: QrGuestSession, table: RestaurantTable}
   */
  public function resolveOrCreate(string $qrPublicId, ?string $existingToken = null): array
  {
    $table = $this->resolveTableForQr($qrPublicId);
    $this->assertQrOrderingEnabled();

    $normalizedToken = $this->normalizeToken($existingToken);
    if ($normalizedToken !== null) {
      $existing = QrGuestSession::query()
        ->where('session_token', $normalizedToken)
        ->where('table_id', (int) $table->id)
        ->where('outlet_id', (int) $table->outlet_id)
        ->first();

      if ($existing !== null && $existing->isActive()) {
        $existing->update(['last_seen_at' => now()]);

        return [
          'session' => $existing->fresh(),
          'table' => $table,
        ];
      }
    }

    $session = QrGuestSession::query()->create([
      'session_token' => $this->generateToken(),
      'outlet_id' => (int) $table->outlet_id,
      'table_id' => (int) $table->id,
      'qr_public_id' => (string) $table->qr_public_id,
      'status' => 'active',
      'expires_at' => now()->addHours(self::BROWSING_TTL_HOURS),
      'last_seen_at' => now(),
    ]);

    return [
      'session' => $session,
      'table' => $table,
    ];
  }

  public function findActiveByToken(string $token): ?QrGuestSession
  {
    $normalized = $this->normalizeToken($token);
    if ($normalized === null) {
      return null;
    }

    $session = QrGuestSession::query()
      ->where('session_token', $normalized)
      ->first();

    if ($session === null || ! $session->isActive()) {
      return null;
    }

    $session->update(['last_seen_at' => now()]);

    return $session->fresh();
  }

  public function assertCanSubmit(QrGuestSession $session, string $qrPublicId, int $outletId, int $tableId): void
  {
    $this->assertQrOrderingEnabled();

    if (! $session->isActive()) {
      throw ValidationException::withMessages([
        'guestSessionToken' => ['Guest session has expired. Please scan the table QR again.'],
      ]);
    }

    $normalizedQr = strtoupper(trim($qrPublicId));
    if (
      (int) $session->outlet_id !== $outletId
      || (int) $session->table_id !== $tableId
      || strtoupper((string) $session->qr_public_id) !== $normalizedQr
    ) {
      throw ValidationException::withMessages([
        'guestSessionToken' => ['Guest session does not match this table.'],
      ]);
    }

    $table = RestaurantTable::query()
      ->whereKey($tableId)
      ->where('outlet_id', $outletId)
      ->first();

    if ($table === null || ! $table->qr_enabled || (string) $table->status !== 'active') {
      throw ValidationException::withMessages([
        'qrPublicId' => ['QR ordering is not available for this table.'],
      ]);
    }

    if (strtoupper((string) $table->qr_public_id) !== $normalizedQr) {
      throw ValidationException::withMessages([
        'qrPublicId' => ['QR code does not match this table.'],
      ]);
    }
  }

  public function closeExpiredSessions(): int
  {
    return QrGuestSession::query()
      ->where('status', 'active')
      ->where('expires_at', '<=', now())
      ->update(['status' => 'closed']);
  }

  /** @return array{token: string, expiresAt: string} */
  public function toPublicPayload(QrGuestSession $session): array
  {
    return [
      'token' => (string) $session->session_token,
      'expiresAt' => $session->expires_at?->toIso8601String() ?? '',
    ];
  }

  private function resolveTableForQr(string $qrPublicId): RestaurantTable
  {
    $normalized = trim($qrPublicId);
    $table = RestaurantTable::query()->where('qr_public_id', $normalized)->first();

    if ($table === null) {
      throw ValidationException::withMessages([
        'qrPublicId' => ['QR code not found.'],
      ]);
    }

    if (! $table->qr_enabled) {
      throw ValidationException::withMessages([
        'qrPublicId' => ['QR code has expired or was disabled.'],
      ]);
    }

    if (! $table->active || (string) $table->status !== 'active') {
      throw ValidationException::withMessages([
        'qrPublicId' => ['This table is currently unavailable.'],
      ]);
    }

    $outlet = Outlet::query()->find((int) $table->outlet_id);
    if ($outlet === null || (string) $outlet->status !== 'active') {
      throw ValidationException::withMessages([
        'qrPublicId' => ['This outlet is currently unavailable.'],
      ]);
    }

    return $table;
  }

  private function assertQrOrderingEnabled(): void
  {
    if (! $this->qrOrderingSettingsService->enableQrOrdering()) {
      throw ValidationException::withMessages([
        'qrOrdering' => ['QR ordering is disabled for this outlet.'],
      ]);
    }
  }

  private function normalizeToken(?string $token): ?string
  {
    if ($token === null) {
      return null;
    }

    $trimmed = trim($token);

    return $trimmed !== '' ? $trimmed : null;
  }

  private function generateToken(): string
  {
    do {
      $candidate = 'QGS_'.strtoupper(Str::random(24));
      $exists = QrGuestSession::query()->where('session_token', $candidate)->exists();
    } while ($exists);

    return $candidate;
  }
}

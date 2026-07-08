<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReservationSetting;
use App\Modules\Menu\Services\PublicOutletMenuService;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicReservationService
{
    public function __construct(
        private readonly ReservationDepositCalculator $depositCalculator,
        private readonly PublicOutletMenuService $menuService,
        private readonly OrderService $orderService,
        private readonly ReservationRealtimePublisher $realtimePublisher,
    ) {}

    public function resolveSettings(string $outletSlug): OutletReservationSetting
    {
        $settings = OutletReservationSetting::query()
            ->where('public_slug', $outletSlug)
            ->where('public_enabled', true)
            ->with('outlet')
            ->first();

        if ($settings === null) {
            throw (new ModelNotFoundException)->setModel(OutletReservationSetting::class, [$outletSlug]);
        }

        return $settings;
    }

    public function showOutletContext(string $outletSlug): array
    {
        $settings = $this->resolveSettings($outletSlug);
        $outlet = $settings->outlet;
        if ($outlet === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class);
        }

        return [
            'outlet' => [
                'id' => (int) $outlet->id,
                'name' => (string) $outlet->name,
                'address' => (string) ($outlet->address ?? ''),
                'phone' => (string) ($outlet->phone ?? ''),
            ],
            'settings' => $this->settingsPayload($settings),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $outletSlug, array $data): Reservation
    {
        $settings = $this->resolveSettings($outletSlug);
        $outlet = $settings->outlet;
        if ($outlet === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class);
        }

        $partySize = (int) $data['partySize'];
        $minParty = (int) config('reservations.party_size_min', 1);
        $maxParty = (int) config('reservations.party_size_max', 50);
        if ($partySize < $minParty || $partySize > $maxParty) {
            throw ValidationException::withMessages([
                'partySize' => ["Party size must be between {$minParty} and {$maxParty}."],
            ]);
        }

        $reservationAt = (string) $data['reservationAt'];
        if (now()->greaterThanOrEqualTo($reservationAt)) {
            throw ValidationException::withMessages([
                'reservationAt' => ['Reservation time must be in the future.'],
            ]);
        }

        /** @var array<int, array<string, mixed>> $rawItems */
        $rawItems = $data['items'] ?? [];
        $this->depositCalculator->assertPreorderRules($settings, $rawItems);
        $totals = $this->menuService->resolvePreorderTotals($outlet, $rawItems);
        $requiredDeposit = $this->depositCalculator->calculate($settings, (float) $totals['total']);

        return DB::transaction(function () use ($settings, $outlet, $data, $partySize, $reservationAt, $totals, $requiredDeposit): Reservation {
            $orderCode = 'RSV-PRE-'.now()->format('YmdHis').'-'.strtoupper((string) str()->random(4));
            $order = $this->orderService->create(new CreateOrderData(
                tenantId: 1,
                outletId: (int) $outlet->id,
                code: $orderCode,
                source: 'reservation_public',
                orderType: 'Reservation Pre-order',
                status: 'confirmed',
                paymentStatus: 'unpaid',
                items: $totals['items'],
                payments: [],
                subtotal: (float) $totals['subtotal'],
                tax: (float) $totals['tax'],
                total: (float) $totals['total'],
                discountAmount: 0,
                customerName: (string) $data['customerName'],
                customerPhone: isset($data['customerPhone']) ? (string) $data['customerPhone'] : null,
                memberId: null,
                tableId: null,
                tableNumber: null,
                createdAt: now()->toISOString(),
                confirmedAt: now()->toISOString(),
                splitBill: null,
                serviceMode: null,
                orderChannel: 'reservation_deposit',
                posSessionId: null,
            ));

            $reservation = Reservation::query()->create([
                'outlet_id' => (int) $outlet->id,
                'table_id' => null,
                'reservation_code' => $this->generateReservationCode(),
                'customer_name' => (string) $data['customerName'],
                'customer_phone' => isset($data['customerPhone']) ? (string) $data['customerPhone'] : null,
                'party_size' => $partySize,
                'reservation_at' => $reservationAt,
                'status' => 'pending_deposit',
                'source' => 'public',
                'required_deposit_amount' => $requiredDeposit,
                'linked_order_id' => (int) $order->id,
            ]);

            $fresh = $reservation->fresh(['linkedOrder.items']) ?? $reservation;
            $this->realtimePublisher->publishCreated($fresh);

            return $fresh;
        });
    }

    public function showByCode(string $reservationCode): Reservation
    {
        $reservation = Reservation::query()
            ->where('reservation_code', $reservationCode)
            ->where('source', 'public')
            ->with(['linkedOrder.items', 'depositProofs', 'outlet'])
            ->first();

        if ($reservation === null) {
            throw (new ModelNotFoundException)->setModel(Reservation::class, [$reservationCode]);
        }

        return $reservation;
    }

    /** @return array<string, mixed> */
    private function settingsPayload(OutletReservationSetting $settings): array
    {
        return [
            'depositMode' => (string) $settings->deposit_mode,
            'depositPercent' => $settings->deposit_percent !== null ? (float) $settings->deposit_percent : null,
            'depositFlatAmount' => $settings->deposit_flat_amount !== null ? (float) $settings->deposit_flat_amount : null,
            'preorderRequired' => (bool) $settings->preorder_required,
            'depositInstructions' => $settings->deposit_instructions,
        ];
    }

    private function generateReservationCode(): string
    {
        return 'RSV-'.now()->format('YmdHis').'-'.strtoupper((string) str()->random(4));
    }
}

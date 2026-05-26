<?php

namespace App\Modules\Settings\Services;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletPaymentMethodConfig;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use App\Modules\Settings\Support\PaymentMethodCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class OutletPaymentMethodConfigService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listConfigsForOutlet(User $user, int $outletId): array
    {
        $this->assertOutletAccess($user, $outletId);
        $this->ensureDefaultsForOutlet($outletId);

        return OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outletId)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (OutletPaymentMethodConfig $row): array => $this->serialize($row))
            ->all();
    }

    /** Enabled methods for POS/Cashier checkout tiles. */
    /** @return list<array<string, mixed>> */
    public function listCheckoutMethods(User $user, int $outletId): array
    {
        $this->assertOutletAccess($user, $outletId);
        $this->ensureDefaultsForOutlet($outletId);

        return OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outletId)
            ->where('enabled', true)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (OutletPaymentMethodConfig $row): array => $this->serializeCheckout($row))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function syncConfigs(User $user, int $outletId, array $rows): array
    {
        $this->assertOutletAccess($user, $outletId);
        $this->assertOutletExists($outletId);

        return DB::transaction(function () use ($outletId, $rows): array {
            $this->ensureDefaultsForOutlet($outletId);

            foreach ($rows as $index => $row) {
                $code = (string) ($row['paymentMethodCode'] ?? $row['payment_method_code'] ?? '');
                if ($code === '') {
                    throw ValidationException::withMessages([
                        "configs.{$index}.paymentMethodCode" => ['Payment method code is required.'],
                    ]);
                }

                $config = OutletPaymentMethodConfig::query()
                    ->where('outlet_id', $outletId)
                    ->where('payment_method_code', $code)
                    ->first();

                if ($config === null) {
                    throw ValidationException::withMessages([
                        "configs.{$index}.paymentMethodCode" => ["Unknown payment method code: {$code}"],
                    ]);
                }

                $incomingSettings = is_array($row['settings'] ?? null) ? $row['settings'] : [];
                $mergedSettings = array_merge($config->settings ?? [], $incomingSettings);

                $config->fill([
                    'enabled' => (bool) ($row['enabled'] ?? $config->enabled),
                    'display_order' => (int) ($row['displayOrder'] ?? $row['display_order'] ?? $config->display_order),
                    'is_default' => (bool) ($row['isDefault'] ?? $row['is_default'] ?? $config->is_default),
                    'provider' => isset($row['provider']) ? ($row['provider'] !== null ? (string) $row['provider'] : null) : $config->provider,
                    'settings' => $mergedSettings,
                ]);
                $config->save();
            }

            $enabledDefaults = OutletPaymentMethodConfig::query()
                ->where('outlet_id', $outletId)
                ->where('enabled', true)
                ->orderBy('display_order')
                ->get();

            if ($enabledDefaults->isEmpty()) {
                throw ValidationException::withMessages([
                    'configs' => ['At least one payment method must remain enabled.'],
                ]);
            }

            if (! $enabledDefaults->contains(fn (OutletPaymentMethodConfig $c): bool => $c->is_default)) {
                $first = $enabledDefaults->first();
                if ($first !== null) {
                    OutletPaymentMethodConfig::query()
                        ->where('outlet_id', $outletId)
                        ->update(['is_default' => false]);
                    $first->is_default = true;
                    $first->save();
                }
            }

            return $this->listConfigsForOutletInternal($outletId);
        });
    }

    /** @return array<string, mixed> */
    public function uploadStaticQrisImage(User $user, int $outletId, UploadedFile $file): array
    {
        $this->assertOutletAccess($user, $outletId);
        $this->ensureDefaultsForOutlet($outletId);

        $config = OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outletId)
            ->where('payment_method_code', 'manual_qris')
            ->first();

        if ($config === null) {
            throw ValidationException::withMessages(['manual_qris' => ['Manual QRIS is not configured for this outlet.']]);
        }

        $path = $file->store("outlets/{$outletId}/payment-methods", 'public');
        $settings = $config->settings ?? [];
        $settings['qr_image_path'] = $path;
        $settings['qr_image_url'] = Storage::disk('public')->url($path);
        $config->settings = $settings;
        $config->save();

        return $this->serialize($config->fresh());
    }

    public function assertGatewayInitiationAllowed(int $outletId, ?string $paymentMethod): void
    {
        $this->ensureDefaultsForOutlet($outletId);

        $gatewayRow = $this->resolveGatewayConfigForPaymentMethod($outletId, $paymentMethod);
        if ($gatewayRow === null || ! $gatewayRow->enabled) {
            throw ValidationException::withMessages([
                'paymentMethod' => ['Gateway payment is disabled for this outlet. Enable gateway QRIS in outlet payment settings or use Cash / static QRIS.'],
            ]);
        }
    }

    public function findEnabledConfigByCode(int $outletId, string $code): ?OutletPaymentMethodConfig
    {
        $this->ensureDefaultsForOutlet($outletId);

        return OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outletId)
            ->where('payment_method_code', $code)
            ->where('enabled', true)
            ->first();
    }

    public function ensureDefaultsForOutlet(int $outletId): void
    {
        if (OutletPaymentMethodConfig::query()->where('outlet_id', $outletId)->exists()) {
            return;
        }

        foreach (PaymentMethodCatalog::defaultRows() as $row) {
            OutletPaymentMethodConfig::query()->create([
                'outlet_id' => $outletId,
                'payment_method_code' => $row['paymentMethodCode'],
                'type' => $row['type'],
                'provider' => $row['provider'],
                'enabled' => $row['enabled'],
                'display_order' => $row['displayOrder'],
                'is_default' => $row['isDefault'],
                'settings' => $row['settings'],
            ]);
        }
    }

    private function resolveGatewayConfigForPaymentMethod(int $outletId, ?string $paymentMethod): ?OutletPaymentMethodConfig
    {
        $normalized = strtolower(trim((string) $paymentMethod));
        $candidates = match ($normalized) {
            'qris' => ['gateway_qris'],
            'ewallet', 'cashless' => ['gateway_ewallet', 'gateway_qris'],
            'bank_transfer', 'transfer' => ['manual_transfer', 'gateway_ewallet'],
            default => ['gateway_qris', 'gateway_ewallet'],
        };

        return OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outletId)
            ->whereIn('payment_method_code', $candidates)
            ->whereIn('type', [
                PaymentMethodCatalog::TYPE_GATEWAY_QRIS,
                PaymentMethodCatalog::TYPE_FUTURE_GATEWAY,
                PaymentMethodCatalog::TYPE_FUTURE_TERMINAL,
            ])
            ->orderBy('display_order')
            ->first();
    }

    private function assertOutletAccess(User $user, int $outletId): void
    {
        if (! in_array($outletId, $this->outletAccessResolver->allowedOutletIds($user), true)) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $outletId]);
        }
    }

    private function assertOutletExists(int $outletId): void
    {
        if (! Outlet::query()->whereKey($outletId)->exists()) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $outletId]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function listConfigsForOutletInternal(int $outletId): array
    {
        return OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outletId)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (OutletPaymentMethodConfig $row): array => $this->serialize($row))
            ->all();
    }

    /** @return array<string, mixed> */
    private function serialize(OutletPaymentMethodConfig $row): array
    {
        $settings = $row->settings ?? [];
        if (isset($settings['qr_image_path']) && ! isset($settings['qr_image_url'])) {
            $settings['qr_image_url'] = Storage::disk('public')->url((string) $settings['qr_image_path']);
        }

        return [
            'id' => (int) $row->id,
            'outletId' => (int) $row->outlet_id,
            'paymentMethodCode' => (string) $row->payment_method_code,
            'type' => (string) $row->type,
            'provider' => $row->provider,
            'enabled' => (bool) $row->enabled,
            'displayOrder' => (int) $row->display_order,
            'isDefault' => (bool) $row->is_default,
            'label' => PaymentMethodCatalog::displayLabel((string) $row->payment_method_code, (string) $row->type),
            'settlementMethod' => PaymentMethodCatalog::settlementMethodForType((string) $row->type),
            'settings' => $settings,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeCheckout(OutletPaymentMethodConfig $row): array
    {
        $base = $this->serialize($row);
        $base['isGateway'] = PaymentMethodCatalog::isGatewayType((string) $row->type);
        $base['isManualQris'] = PaymentMethodCatalog::isManualQrisType((string) $row->type);
        $base['isCash'] = PaymentMethodCatalog::isCashType((string) $row->type);

        return $base;
    }
}

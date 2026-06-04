<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class LoyaltyVoucherService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return Collection<int, LoyaltyVoucher>
     */
    public function list(?User $user, int $outletId, ?bool $isActive = null): Collection
    {
        $this->assertOutletAllowed($user, $outletId);

        $query = LoyaltyVoucher::query()
            ->where('outlet_id', $outletId)
            ->orderBy('name');

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        return $query->get();
    }

    public function findScoped(?User $user, int $voucherId): ?LoyaltyVoucher
    {
        $voucher = LoyaltyVoucher::query()->whereKey($voucherId)->first();
        if ($voucher === null) {
            return null;
        }

        $this->assertOutletAllowed($user, (int) $voucher->outlet_id);

        return $voucher;
    }

    public function findActiveForIssuance(int $voucherId, int $outletId): LoyaltyVoucher
    {
        $voucher = LoyaltyVoucher::query()
            ->whereKey($voucherId)
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->first();

        if ($voucher === null) {
            throw ValidationException::withMessages([
                'voucherId' => ['Voucher not found or inactive for this outlet.'],
            ]);
        }

        $this->validateVoucherWindow($voucher);

        return $voucher;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(?User $user, array $payload): LoyaltyVoucher
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        if ($outletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['Outlet is required.'],
            ]);
        }

        $this->assertOutletAllowed($user, $outletId);

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $name = trim((string) ($payload['name'] ?? ''));
        if ($code === '' || $name === '') {
            throw ValidationException::withMessages([
                'code' => ['Code and name are required.'],
            ]);
        }

        $voucherType = (string) ($payload['voucherType'] ?? LoyaltyVoucher::TYPE_MANUAL);
        $valueType = (string) ($payload['valueType'] ?? '');
        $this->assertVoucherType($voucherType);
        $this->assertValueType($valueType);
        $value = $this->normalizeValue($valueType, $payload['value'] ?? 0);

        $this->assertCodeUnique($outletId, $code);

        return LoyaltyVoucher::query()->create([
            'outlet_id' => $outletId,
            'code' => $code,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'voucher_type' => $voucherType,
            'value_type' => $valueType,
            'value' => $value,
            'minimum_spend' => (float) ($payload['minimumSpend'] ?? 0),
            'valid_from' => $payload['validFrom'] ?? null,
            'valid_until' => $payload['validUntil'] ?? null,
            'is_active' => $payload['isActive'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(?User $user, LoyaltyVoucher $voucher, array $payload): LoyaltyVoucher
    {
        $this->assertOutletAllowed($user, (int) $voucher->outlet_id);

        $attributes = [];
        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '') {
                throw ValidationException::withMessages([
                    'name' => ['Name is required.'],
                ]);
            }
            $attributes['name'] = $name;
        }
        if (array_key_exists('description', $payload)) {
            $attributes['description'] = $payload['description'];
        }
        if (array_key_exists('code', $payload)) {
            $code = strtoupper(trim((string) $payload['code']));
            if ($code === '') {
                throw ValidationException::withMessages([
                    'code' => ['Code is required.'],
                ]);
            }
            $this->assertCodeUnique((int) $voucher->outlet_id, $code, (int) $voucher->id);
            $attributes['code'] = $code;
        }
        if (array_key_exists('voucherType', $payload)) {
            $this->assertVoucherType((string) $payload['voucherType']);
            $attributes['voucher_type'] = (string) $payload['voucherType'];
        }
        if (array_key_exists('valueType', $payload)) {
            $this->assertValueType((string) $payload['valueType']);
            $attributes['value_type'] = (string) $payload['valueType'];
        }
        if (array_key_exists('value', $payload)) {
            $valueType = (string) ($attributes['value_type'] ?? $voucher->value_type);
            $attributes['value'] = $this->normalizeValue($valueType, $payload['value']);
        }
        if (array_key_exists('minimumSpend', $payload)) {
            $attributes['minimum_spend'] = (float) $payload['minimumSpend'];
        }
        if (array_key_exists('validFrom', $payload)) {
            $attributes['valid_from'] = $payload['validFrom'];
        }
        if (array_key_exists('validUntil', $payload)) {
            $attributes['valid_until'] = $payload['validUntil'];
        }

        if ($attributes !== []) {
            $voucher->update($attributes);
        }

        return $voucher->fresh() ?? $voucher;
    }

    public function setActive(?User $user, LoyaltyVoucher $voucher, bool $isActive): LoyaltyVoucher
    {
        $this->assertOutletAllowed($user, (int) $voucher->outlet_id);
        $voucher->update(['is_active' => $isActive]);

        return $voucher->fresh() ?? $voucher;
    }

    public function validateVoucherWindow(LoyaltyVoucher $voucher, ?Carbon $asOf = null): void
    {
        $asOf ??= now();

        if ($voucher->valid_from !== null && $asOf->lt($voucher->valid_from)) {
            throw ValidationException::withMessages([
                'voucherId' => ['Voucher is not yet valid.'],
            ]);
        }

        if ($voucher->valid_until !== null && $asOf->gt($voucher->valid_until)) {
            throw ValidationException::withMessages([
                'voucherId' => ['Voucher validity has ended.'],
            ]);
        }
    }

    private function normalizeValue(string $valueType, mixed $value): float
    {
        $numeric = (float) $value;

        if ($valueType === LoyaltyVoucher::VALUE_FREE_ITEM) {
            return max(0, $numeric);
        }

        if ($valueType === LoyaltyVoucher::VALUE_PERCENTAGE) {
            if ($numeric <= 0 || $numeric > 100) {
                throw ValidationException::withMessages([
                    'value' => ['Percentage value must be between 0 and 100.'],
                ]);
            }

            return $numeric;
        }

        if ($numeric <= 0) {
            throw ValidationException::withMessages([
                'value' => ['Value must be greater than zero.'],
            ]);
        }

        return $numeric;
    }

    private function assertVoucherType(string $type): void
    {
        if (! in_array($type, LoyaltyVoucher::TYPES, true)) {
            throw ValidationException::withMessages([
                'voucherType' => ['Invalid voucher type.'],
            ]);
        }
    }

    private function assertValueType(string $type): void
    {
        if (! in_array($type, LoyaltyVoucher::VALUE_TYPES, true)) {
            throw ValidationException::withMessages([
                'valueType' => ['Invalid value type.'],
            ]);
        }
    }

    private function assertCodeUnique(int $outletId, string $code, ?int $ignoreId = null): void
    {
        $exists = LoyaltyVoucher::query()
            ->where('outlet_id', $outletId)
            ->where('code', $code)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Voucher code must be unique for this outlet.'],
            ]);
        }
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($allowed !== null && ! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outlet is not allowed for this user.'],
            ]);
        }
    }
}

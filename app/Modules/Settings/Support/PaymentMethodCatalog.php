<?php

namespace App\Modules\Settings\Support;

/**
 * Canonical payment method definitions for outlet configuration (capability-driven, not provider-hardcoded).
 */
final class PaymentMethodCatalog
{
    public const TYPE_CASH = 'cash';

    public const TYPE_MANUAL_QRIS = 'manual_qris';

    public const TYPE_GATEWAY_QRIS = 'gateway_qris';

    public const TYPE_MANUAL_TRANSFER = 'manual_transfer';

    public const TYPE_FUTURE_GATEWAY = 'future_gateway';

    public const TYPE_FUTURE_TERMINAL = 'future_terminal';

    /** @return list<array{paymentMethodCode: string, type: string, provider: ?string, displayOrder: int, enabled: bool, isDefault: bool, settings: array<string, mixed>}> */
    public static function defaultRows(): array
    {
        return [
            [
                'paymentMethodCode' => 'cash',
                'type' => self::TYPE_CASH,
                'provider' => null,
                'displayOrder' => 10,
                'enabled' => true,
                'isDefault' => true,
                'settings' => [],
            ],
            [
                'paymentMethodCode' => 'manual_qris',
                'type' => self::TYPE_MANUAL_QRIS,
                'provider' => 'manual',
                'displayOrder' => 20,
                'enabled' => true,
                'isDefault' => false,
                'settings' => [
                    'instructions' => 'Scan the outlet QRIS, then confirm payment after verification.',
                ],
            ],
            [
                'paymentMethodCode' => 'gateway_qris',
                'type' => self::TYPE_GATEWAY_QRIS,
                'provider' => null,
                'displayOrder' => 30,
                'enabled' => false,
                'isDefault' => false,
                'settings' => [],
            ],
            [
                'paymentMethodCode' => 'gateway_ewallet',
                'type' => self::TYPE_FUTURE_GATEWAY,
                'provider' => null,
                'displayOrder' => 40,
                'enabled' => false,
                'isDefault' => false,
                'settings' => [],
            ],
            [
                'paymentMethodCode' => 'manual_transfer',
                'type' => self::TYPE_MANUAL_TRANSFER,
                'provider' => 'manual',
                'displayOrder' => 50,
                'enabled' => false,
                'isDefault' => false,
                'settings' => [],
            ],
        ];
    }

    public static function isGatewayType(string $type): bool
    {
        return in_array($type, [self::TYPE_GATEWAY_QRIS, self::TYPE_FUTURE_GATEWAY, self::TYPE_FUTURE_TERMINAL], true);
    }

    public static function isManualQrisType(string $type): bool
    {
        return $type === self::TYPE_MANUAL_QRIS;
    }

    public static function isCashType(string $type): bool
    {
        return $type === self::TYPE_CASH;
    }

    public static function displayLabel(string $code, string $type): string
    {
        return match ($type) {
            self::TYPE_CASH => 'Cash',
            self::TYPE_MANUAL_QRIS => 'QRIS',
            self::TYPE_GATEWAY_QRIS => 'QRIS Online',
            self::TYPE_MANUAL_TRANSFER => 'Bank Transfer',
            self::TYPE_FUTURE_GATEWAY => match ($code) {
                'gateway_ewallet' => 'E-Wallet',
                default => 'Online Payment',
            },
            self::TYPE_FUTURE_TERMINAL => 'Card Terminal',
            default => ucfirst(str_replace('_', ' ', $code)),
        };
    }

    /** Maps POS settlement `payments.method` column value. */
    public static function settlementMethodForType(string $type): string
    {
        return match ($type) {
            self::TYPE_CASH => 'cash',
            self::TYPE_MANUAL_QRIS, self::TYPE_GATEWAY_QRIS => 'qris',
            self::TYPE_MANUAL_TRANSFER => 'transfer',
            self::TYPE_FUTURE_GATEWAY => 'ewallet',
            self::TYPE_FUTURE_TERMINAL => 'card',
            default => 'cash',
        };
    }
}

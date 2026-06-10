<?php

namespace App\Modules\System\Services;

final class AuditRiskClassificationService
{
    public const RISK_INFO = 'info';

    public const RISK_WARNING = 'warning';

    public const RISK_CRITICAL = 'critical';

    /** @var list<string> */
    private const CRITICAL_ACTIONS = [
        'reversal_created',
        'reversal_rejected',
        'accounting.journal.posted',
        'void_posted',
        'pos_payment_voided',
        'pos_refund_posted',
        'gift_card_redemption_reversed',
        'gift_card_refund_exposure',
        'procurement_posting_reversed',
        'posting_reversed',
        'posting_created',
        'finalized',
        'closed',
        'reopened',
        'unauthorized_access_attempt',
        'payment_configuration_updated',
        'role_permission_changed',
        'role_updated',
        'permission_granted',
        'permission_revoked',
        'inventory_valuation_rebuilt',
        'inventory.movement.recorded',
        'gift_card_reconciliation_override',
    ];

    /** @var list<string> */
    private const WARNING_ACTIONS = [
        'purchase_order_approved',
        'purchase_order.approved',
        'purchase_request_approved',
        'purchase_invoice_approved',
        'goods_receipt_received',
        'goods_receipt.posted',
        'supplier_payment_approved',
        'inventory_valuation_variance_detected',
        'inventory.ingredient.updated',
        'inventory.ingredient.deleted',
        'payroll_run_approved',
        'corrected',
        'manual_adjustment',
    ];

    /** @var list<string> */
    private const INFO_ACTION_PATTERNS = [
        'viewed',
        'generated',
        'dashboard',
        'analytics',
        'opened',
        'listed',
    ];

    public function classify(string $module, string $entityType, string $action): string
    {
        $normalized = strtolower($action);

        if ($this->matchesAny($normalized, self::WARNING_ACTIONS)) {
            return self::RISK_WARNING;
        }

        if ($this->matchesAny($normalized, self::CRITICAL_ACTIONS)) {
            return self::RISK_CRITICAL;
        }

        if ($module === 'payroll' && in_array($normalized, ['approved', 'finalized', 'closed', 'posting_created'], true)) {
            return self::RISK_CRITICAL;
        }

        if ($module === 'accounting' && (str_contains($normalized, 'reversal') || str_contains($normalized, 'void'))) {
            return self::RISK_CRITICAL;
        }

        if ($module === 'payments' && (str_contains($normalized, 'void') || str_contains($normalized, 'config'))) {
            return self::RISK_CRITICAL;
        }

        if ($module === 'gift_cards' && str_contains($normalized, 'reconcil')) {
            return self::RISK_CRITICAL;
        }

        if (str_contains($normalized, 'adjust')) {
            return self::RISK_WARNING;
        }

        foreach (self::INFO_ACTION_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return self::RISK_INFO;
            }
        }

        return self::RISK_INFO;
    }

    public function isCritical(string $module, string $entityType, string $action): bool
    {
        return $this->classify($module, $entityType, $action) === self::RISK_CRITICAL;
    }

    /**
     * @param  list<string>  $needles
     */
    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($haystack === $needle || str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}

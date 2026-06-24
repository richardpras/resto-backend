<?php

namespace Database\Seeders\Support;

final class PostingMappingDefaults
{
    /** @return array<string, string> rule_key => account_code */
    public static function procurement(): array
    {
        return [
            'procurement.grn.inventory' => '1300',
            'procurement.grn.grni' => '2140',
            'procurement.invoice.grni' => '2140',
            'procurement.invoice.accounts_payable' => '2100',
            'procurement.payment.accounts_payable' => '2100',
            'procurement.payment.cash' => '1100',
            'procurement.payment.bank' => '1110',
        ];
    }

    /** @return array<string, string> */
    public static function payroll(): array
    {
        return [
            'payroll.expense' => '6100',
            'payroll.salary_payable' => '2150',
            'payroll.pph21_payable' => '2160',
            'payroll.bpjs_payable' => '2170',
            'payroll.loan_receivable' => '1210',
            'payroll.cash_advance_recovery' => '1220',
            'payroll.other_deductions' => '2180',
        ];
    }

    /** @return array<string, string> */
    public static function pos(): array
    {
        return [
            'pos.sales.revenue' => '4100',
            'pos.sales.cogs' => '5100',
            'pos.sales.inventory' => '1300',
            'pos.redemption.gift_card' => '2130',
            'pos.redemption.store_credit' => '2135',
            'pos.cash.variance' => '5400',
            'pos.payment.cash' => '1100',
            'pos.payment.transfer' => '1111',
            'pos.payment.card' => '1110',
            'pos.payment.qris' => '1120',
            'pos.payment.ewallet' => '1120',
            'pos.gift_card.issue.cash' => '1100',
            'pos.gift_card.issue.gift_card' => '2130',
            'pos.gift_card.issue.store_credit' => '2135',
            'pos.gift_card.breakage.revenue' => '4190',
        ];
    }

    /** @return array<string, string> */
    public static function inventory(): array
    {
        return [
            'inventory.asset' => '1300',
            'inventory.adjustment' => '5300',
            'inventory.waste' => '5200',
        ];
    }

    /** @return array<string, array<string, string>> */
    public static function allModules(): array
    {
        return [
            'procurement' => self::procurement(),
            'payroll' => self::payroll(),
            'pos' => self::pos(),
            'inventory' => self::inventory(),
        ];
    }
}

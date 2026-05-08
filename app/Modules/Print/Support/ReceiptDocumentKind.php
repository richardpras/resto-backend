<?php

namespace App\Modules\Print\Support;

enum ReceiptDocumentKind: string
{
    case CustomerReceipt = 'customer_receipt';
    case KitchenChit = 'kitchen_chit';
    case PaymentReceipt = 'payment_receipt';
    case QrReceipt = 'qr_receipt';
    case CashierCloseSummary = 'cashier_close_summary';
    case FiscalInvoice = 'fiscal_invoice';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

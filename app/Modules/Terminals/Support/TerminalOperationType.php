<?php

namespace App\Modules\Terminals\Support;

/**
 * Sync replay operation taxonomy (additive; clients queue these while offline).
 */
final class TerminalOperationType
{
    public const ORDER_CREATE = 'order.create';

    public const ORDER_UPDATE = 'order.update';

    public const ORDER_UPDATE_STATUS = 'order.update_status';

    public const ORDER_ADD_PAYMENTS = 'order.add_payments';

    public const PAYMENT_TRANSACTION_INITIATE = 'payment.transaction.initiate';

    public const KITCHEN_TICKET_STATUS = 'kitchen.ticket.status';

    public const QR_ORDER_CONFIRM = 'qr_order.confirm';

    public const QR_ORDER_REJECT = 'qr_order.reject';

    public const PRINT_JOB_RETRY = 'print.job.retry';

    public const POS_SESSION_OPEN = 'pos_session.open';

    public const POS_SESSION_CLOSE = 'pos_session.close';

    /** Phase 14 — enqueue print from persisted render snapshot (offline/deferred replay). */
    public const PRINT_DOCUMENT_ENQUEUE = 'print.document.enqueue';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::ORDER_CREATE,
            self::ORDER_UPDATE,
            self::ORDER_UPDATE_STATUS,
            self::ORDER_ADD_PAYMENTS,
            self::PAYMENT_TRANSACTION_INITIATE,
            self::KITCHEN_TICKET_STATUS,
            self::QR_ORDER_CONFIRM,
            self::QR_ORDER_REJECT,
            self::PRINT_JOB_RETRY,
            self::POS_SESSION_OPEN,
            self::POS_SESSION_CLOSE,
            self::PRINT_DOCUMENT_ENQUEUE,
        ];
    }
}

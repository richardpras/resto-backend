<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\AccountingPeriodController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\ReportController;
use App\Modules\HR\Http\Controllers\AdjustmentController;
use App\Modules\HR\Http\Controllers\AttendanceController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\LoanController;
use App\Modules\HR\Http\Controllers\OvertimeController;
use App\Modules\HR\Http\Controllers\PayrollController;
use App\Modules\HR\Http\Controllers\ShiftController;
use App\Modules\Hardware\Http\Controllers\HardwareBridgeController;
use App\Modules\Inventory\Http\Controllers\IngredientController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Kitchen\Http\Controllers\KitchenTicketController;
use App\Modules\GiftCards\Http\Controllers\GiftCardController;
use App\Modules\Loyalty\Http\Controllers\CrmMetricsController;
use App\Modules\Loyalty\Http\Controllers\CustomerController;
use App\Modules\Loyalty\Http\Controllers\MembershipTierController;
use App\Modules\Members\Http\Controllers\MemberController;
use App\Modules\Menu\Http\Controllers\MenuItemController;
use App\Modules\Monitoring\Http\Controllers\MonitoringMetricsController;
use App\Modules\Monitoring\Http\Controllers\DashboardSummaryController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Orders\Http\Controllers\OrderItemRecoveryController;
use App\Modules\Orders\Http\Controllers\OrderItemRecoverySettlementController;
use App\Modules\Orders\Http\Controllers\PosSessionController;
use App\Modules\Orders\Http\Controllers\QrOrderController;
use App\Modules\Orders\Http\Controllers\TableMasterController;
use App\Modules\Payments\Http\Controllers\PaymentTransactionController;
use App\Modules\Payments\Http\Controllers\XenditSandboxSimulationController;
use App\Modules\Payments\Http\Controllers\XenditInvoiceWebhookController;
use App\Modules\Print\Http\Controllers\PrinterProfileController;
use App\Modules\Print\Http\Controllers\PrinterRouteController;
use App\Modules\Print\Http\Controllers\PrintQueueController;
use App\Modules\Print\Http\Controllers\ReceiptDocumentController;
use App\Modules\Print\Http\Controllers\ReceiptLayoutsController;
use App\Modules\Purchase\Http\Controllers\GoodsReceiptController;
use App\Modules\Purchase\Http\Controllers\PurchaseInvoiceController;
use App\Modules\Purchase\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchase\Http\Controllers\PurchaseRequestController;
use App\Modules\Promotions\Http\Controllers\CouponValidationController;
use App\Modules\Settings\Http\Controllers\BankAccountSettingsCrudController;
use App\Modules\Settings\Http\Controllers\IntegrationSettingsController;
use App\Modules\Settings\Http\Controllers\MerchantSettingsController;
use App\Modules\Settings\Http\Controllers\NumberingSettingsController;
use App\Modules\Settings\Http\Controllers\OutletReceiptSettingsController;
use App\Modules\Settings\Http\Controllers\OutletSettingsCrudController;
use App\Modules\Settings\Http\Controllers\PaymentMethodSettingsCrudController;
use App\Modules\Settings\Http\Controllers\PrinterSettingsCrudController;
use App\Modules\Settings\Http\Controllers\SystemSettingsController;
use App\Modules\Settings\Http\Controllers\TaxSettingsCrudController;
use App\Modules\Suppliers\Http\Controllers\SupplierController;
use App\Modules\Terminals\Http\Controllers\TerminalDeviceController;
use App\Modules\Terminals\Http\Controllers\TerminalSyncController;
use App\Modules\UserManagement\Http\Controllers\AuthController;
use App\Modules\UserManagement\Http\Controllers\PermissionController;
use App\Modules\UserManagement\Http\Controllers\RoleController;
use App\Modules\UserManagement\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('qr-orders', [QrOrderController::class, 'store']);

    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::patch('orders/{order}', [OrderController::class, 'update']);
    Route::post('orders/{order}/splits', [OrderController::class, 'storeSplit']);
    Route::patch('orders/{order}/splits/{split}', [OrderController::class, 'updateSplit']);
    Route::post('orders/{order}/payments', [OrderController::class, 'addPayments']);
    Route::get('orders/{order}/payments', [OrderController::class, 'listPayments'])->middleware('auth:api');
    Route::get('orders/{order}/events', [OrderController::class, 'listEvents'])->middleware('auth:api');
    Route::post('payment-webhooks/{provider}', [PaymentTransactionController::class, 'webhook']);
    Route::post('payments/webhooks/xendit', [XenditInvoiceWebhookController::class, 'store']);
    Route::post('orders/shift-close', [OrderController::class, 'closeShift']);
    Route::apiResource('menu-items', MenuItemController::class)
        ->only(['index', 'store', 'show', 'update'])
        ->middleware(['auth:api', 'permission:pos.use']);

    Route::apiResource('ingredients', IngredientController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middleware(['auth:api', 'permission:pos.use']);
    Route::get('stock-movements', [StockMovementController::class, 'index'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('stock-movements', [StockMovementController::class, 'store'])->middleware(['auth:api', 'permission:pos.use']);

    Route::apiResource('accounts', AccountController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middleware(['auth:api', 'permission:accounting.manage']);
    Route::apiResource('journals', JournalController::class)->only(['index', 'store', 'update', 'destroy'])
        ->middleware(['auth:api', 'permission:accounting.manage']);
    Route::post('journals/{journal}/post', [JournalController::class, 'post'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting-periods', [AccountingPeriodController::class, 'index'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::post('accounting-periods', [AccountingPeriodController::class, 'store'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::post('accounting-periods/{period}/close', [AccountingPeriodController::class, 'close'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::post('accounting-periods/{period}/open', [AccountingPeriodController::class, 'open'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('reports/ledger', [ReportController::class, 'ledger'])->middleware(['auth:api', 'permission:reports.view']);
    Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])->middleware(['auth:api', 'permission:reports.view']);
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware(['auth:api', 'permission:reports.view']);
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware(['auth:api', 'permission:reports.view']);

    Route::middleware('auth:api')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/verify-screen-pin', [AuthController::class, 'verifyScreenPin']);
        Route::put('auth/screen-pin', [AuthController::class, 'updateScreenPin']);

        // Employees: no employee.* permissions exist in seeds/migrations yet; mirror open access of index/store.
        Route::get('employees', [EmployeeController::class, 'index']);
        Route::post('employees', [EmployeeController::class, 'store']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        Route::put('employees/{employee}', [EmployeeController::class, 'update']);
        Route::patch('employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy']);

        Route::get('shifts', [ShiftController::class, 'index']);
        Route::post('shifts', [ShiftController::class, 'store']);
        Route::put('shifts/{shift}', [ShiftController::class, 'update']);
        Route::delete('shifts/{shift}', [ShiftController::class, 'destroy']);

        Route::get('attendances', [AttendanceController::class, 'index']);
        Route::post('attendances/sync', [AttendanceController::class, 'sync']);
        Route::post('attendances/{attendance}/manual-correction', [AttendanceController::class, 'manualCorrection']);

        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::post('attendance', [AttendanceController::class, 'store']);
        Route::patch('attendance/{attendance}', [AttendanceController::class, 'update']);
        Route::delete('attendance/{attendance}', [AttendanceController::class, 'destroy']);

        Route::get('overtime', [OvertimeController::class, 'index']);
        Route::post('overtime', [OvertimeController::class, 'store']);
        Route::patch('overtime/{overtime}', [OvertimeController::class, 'update']);
        Route::delete('overtime/{overtime}', [OvertimeController::class, 'destroy']);

        Route::get('adjustments', [AdjustmentController::class, 'index']);
        Route::post('adjustments', [AdjustmentController::class, 'store']);
        Route::delete('adjustments/{adjustment}', [AdjustmentController::class, 'destroy']);

        Route::get('loans', [LoanController::class, 'index']);
        Route::post('loans', [LoanController::class, 'store']);
        Route::patch('loans/{loan}', [LoanController::class, 'update']);
        Route::delete('loans/{loan}', [LoanController::class, 'destroy']);

        Route::post('payroll/run', [PayrollController::class, 'run']);
        Route::get('payroll', [PayrollController::class, 'listRuns']);
        Route::get('payroll/{id}', [PayrollController::class, 'showDetail']);
        Route::post('payroll/{id}/finalize', [PayrollController::class, 'finalize']);
        Route::post('payroll/{id}/pay', [PayrollController::class, 'pay']);
        Route::post('payroll-lines/{id}/lock', [PayrollController::class, 'lockLine']);
        Route::post('payroll-lines/{id}/unlock', [PayrollController::class, 'unlockLine']);
        Route::post('payroll/{id}/post-journal', [PayrollController::class, 'postJournal']);
        Route::get('payrolls', [PayrollController::class, 'index'])->middleware('permission:payroll.view');
        Route::post('payrolls', [PayrollController::class, 'store'])->middleware('permission:payroll.create');

        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::put('users/{user}/screen-pin', [UserController::class, 'adminSetScreenPin'])->middleware('permission:users.assign_roles');
        Route::delete('users/{user}/screen-pin', [UserController::class, 'adminClearScreenPin'])->middleware('permission:users.assign_roles');
        Route::post('users/{user}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.assign_roles');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::post('roles/{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:roles.assign_permissions');

        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:permissions.view');
        Route::post('permissions', [PermissionController::class, 'store'])->middleware('permission:permissions.create');

        Route::get('merchant-settings', [MerchantSettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::patch('merchant-settings', [MerchantSettingsController::class, 'update'])->middleware('permission:settings.update');

        Route::get('outlets', [OutletSettingsCrudController::class, 'index'])->middleware('permission:settings.view');
        Route::post('outlets', [OutletSettingsCrudController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('outlets/{outletId}', [OutletSettingsCrudController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('outlets/{outletId}', [OutletSettingsCrudController::class, 'destroy'])->middleware('permission:settings.update');

        Route::get('taxes', [TaxSettingsCrudController::class, 'index'])->middleware('permission:settings.view');
        Route::post('taxes', [TaxSettingsCrudController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('taxes/{taxId}', [TaxSettingsCrudController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('taxes/{taxId}', [TaxSettingsCrudController::class, 'destroy'])->middleware('permission:settings.update');

        Route::get('printers', [PrinterSettingsCrudController::class, 'index'])->middleware('permission:settings.view');
        Route::post('printers', [PrinterSettingsCrudController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('printers/{printerId}', [PrinterSettingsCrudController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('printers/{printerId}', [PrinterSettingsCrudController::class, 'destroy'])->middleware('permission:settings.update');

        Route::get('payment-methods', [PaymentMethodSettingsCrudController::class, 'index'])->middleware('permission:settings.view');
        Route::post('payment-methods', [PaymentMethodSettingsCrudController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('payment-methods/{paymentMethodId}', [PaymentMethodSettingsCrudController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('payment-methods/{paymentMethodId}', [PaymentMethodSettingsCrudController::class, 'destroy'])->middleware('permission:settings.update');

        Route::get('bank-accounts', [BankAccountSettingsCrudController::class, 'index'])->middleware('permission:settings.view');
        Route::post('bank-accounts', [BankAccountSettingsCrudController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('bank-accounts/{bankAccountId}', [BankAccountSettingsCrudController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('bank-accounts/{bankAccountId}', [BankAccountSettingsCrudController::class, 'destroy'])->middleware('permission:settings.update');

        Route::get('system-settings', [SystemSettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::patch('system-settings', [SystemSettingsController::class, 'update'])->middleware('permission:settings.update');

        Route::get('integration', [IntegrationSettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::put('integration', [IntegrationSettingsController::class, 'update'])->middleware('permission:settings.update');

        Route::get('numbering-settings', [NumberingSettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::patch('numbering-settings', [NumberingSettingsController::class, 'update'])->middleware('permission:settings.update');

        Route::get('outlet-receipt-settings', [OutletReceiptSettingsController::class, 'index'])->middleware('permission:settings.view');
        Route::patch('outlet-receipt-settings/{outletId}', [OutletReceiptSettingsController::class, 'update'])->middleware('permission:settings.update');

        Route::get('members', [MemberController::class, 'index'])->middleware('permission:members.manage');
        Route::post('members', [MemberController::class, 'store'])->middleware('permission:members.manage');
        Route::patch('members/{member}', [MemberController::class, 'update'])->middleware('permission:members.manage');
        Route::patch('members/{member}/status', [MemberController::class, 'updateStatus'])->middleware('permission:members.manage');
        Route::delete('members/{member}', [MemberController::class, 'destroy'])->middleware('permission:members.manage');

        Route::get('orders/{order}/recovery-events', [OrderItemRecoveryController::class, 'index'])->middleware('permission:orders.recovery.read');
        Route::post('orders/{order}/items/{orderItem}/recovery/report', [OrderItemRecoveryController::class, 'report'])->middleware('permission:orders.recovery.request');
        Route::post('orders/{order}/items/{orderItem}/recovery/approve', [OrderItemRecoveryController::class, 'approve'])->middleware('permission:orders.recovery.approve');
        Route::post('orders/{order}/items/{orderItem}/recovery/settlement/preview', [OrderItemRecoverySettlementController::class, 'preview'])->middleware('permission:orders.recovery.approve');
        Route::post('orders/{order}/items/{orderItem}/recovery/settlement/record', [OrderItemRecoverySettlementController::class, 'record'])->middleware('permission:orders.recovery.approve');

        Route::get('tables', [TableMasterController::class, 'index'])->middleware('permission:tables.view');
        Route::post('tables', [TableMasterController::class, 'store'])->middleware('permission:tables.manage');
        Route::patch('tables/{table}', [TableMasterController::class, 'update'])->middleware('permission:tables.manage');
        Route::delete('tables/{table}', [TableMasterController::class, 'destroy'])->middleware('permission:tables.manage');
        Route::post('pos-sessions/open', [PosSessionController::class, 'open'])->middleware('permission:pos.use');
        Route::post('pos-sessions/{id}/close', [PosSessionController::class, 'close'])->middleware('permission:pos.use');
        Route::get('pos-sessions/current', [PosSessionController::class, 'current'])->middleware('permission:pos.use');
        Route::post('payment-transactions', [PaymentTransactionController::class, 'store'])->middleware('permission:pos.use');
        Route::post('payments/xendit/simulate-paid/{paymentId}', [XenditSandboxSimulationController::class, 'simulatePaid'])->middleware('permission:pos.use');
        Route::post('payments/xendit/simulate-provider/{paymentId}', [XenditSandboxSimulationController::class, 'simulateProvider'])->middleware('permission:pos.use');
        Route::post('terminals/register', [TerminalDeviceController::class, 'register'])->middleware('permission:pos.use');
        Route::post('terminals/heartbeat', [TerminalDeviceController::class, 'heartbeat'])->middleware('permission:pos.use');
        Route::get('terminals', [TerminalDeviceController::class, 'index'])->middleware('permission:pos.use');
        Route::post('terminals/{terminal}/disable', [TerminalDeviceController::class, 'disable'])->middleware('permission:pos.use');
        Route::post('hardware/devices/register', [HardwareBridgeController::class, 'register'])->middleware('permission:pos.use');
        Route::post('hardware/devices/heartbeat', [HardwareBridgeController::class, 'heartbeat'])->middleware('permission:pos.use');
        Route::post('hardware/sessions/open', [HardwareBridgeController::class, 'openSession'])->middleware('permission:pos.use');
        Route::post('hardware/sessions/{session}/close', [HardwareBridgeController::class, 'closeSession'])->middleware('permission:pos.use');
        Route::get('hardware/devices', [HardwareBridgeController::class, 'index'])->middleware('permission:pos.use');
        Route::post('hardware/devices/{device}/disable', [HardwareBridgeController::class, 'disableDevice'])->middleware('permission:pos.use');
        Route::post('hardware/devices/{device}/revoke', [HardwareBridgeController::class, 'revokeDevice'])->middleware('permission:pos.use');
        Route::post('hardware/commands/enqueue', [HardwareBridgeController::class, 'enqueueCommand'])->middleware('permission:pos.use');
        Route::get('hardware/commands/pull', [HardwareBridgeController::class, 'pullCommands'])->middleware('permission:pos.use');
        Route::post('hardware/commands/{command}/ack', [HardwareBridgeController::class, 'ack'])->middleware('permission:pos.use');
        Route::post('hardware/commands/{command}/nack', [HardwareBridgeController::class, 'nack'])->middleware('permission:pos.use');
        Route::post('sync/operations/batch', [TerminalSyncController::class, 'batch'])->middleware('permission:pos.use');
        Route::post('payment-transactions/reconcile', [PaymentTransactionController::class, 'reconcile'])->middleware('permission:pos.use');
        Route::post('payment-transactions/{transaction}/expire', [PaymentTransactionController::class, 'expire'])->middleware('permission:pos.use');
        Route::get('payment-transactions/{transaction}', [PaymentTransactionController::class, 'show'])->middleware('permission:pos.use');
        Route::post('gift-cards/issue', [GiftCardController::class, 'issue'])->middleware('permission:pos.use');
        Route::get('gift-cards/{code}', [GiftCardController::class, 'check'])->middleware('permission:pos.use');
        Route::post('gift-cards/redeem', [GiftCardController::class, 'redeem'])->middleware('permission:pos.use');
        Route::post('gift-cards/settlements', [GiftCardController::class, 'settle'])->middleware('permission:pos.use');
        Route::post('promotions/coupons/validate', [CouponValidationController::class, 'validateCoupon'])->middleware('permission:pos.use');
        Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:members.manage');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:members.manage');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:members.manage');
        Route::get('customers/{customer}/timeline', [CustomerController::class, 'timeline'])->middleware('permission:members.manage');
        Route::get('customers/{customer}/loyalty-ledger', [CustomerController::class, 'loyaltyLedgerIndex'])->middleware('permission:members.manage');
        Route::post('customers/{customer}/loyalty-ledger', [CustomerController::class, 'loyaltyLedger'])->middleware('permission:members.manage');
        Route::post('customers/{customer}/redeem', [CustomerController::class, 'redeem'])->middleware('permission:members.manage');
        Route::post('customers/{customer}/merge', [CustomerController::class, 'merge'])->middleware('permission:members.manage');
        Route::get('membership-tiers', [MembershipTierController::class, 'index'])->middleware('permission:members.manage');
        Route::get('crm/metrics', [CrmMetricsController::class, 'index'])->middleware('permission:members.manage');
        Route::get('crm/customers', [CustomerController::class, 'index'])->middleware('permission:members.manage');
        Route::get('crm/customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:members.manage');
        Route::get('crm/loyalty/tiers', [MembershipTierController::class, 'index'])->middleware('permission:members.manage');
        Route::get('crm/loyalty/points-ledger', [CustomerController::class, 'crmLoyaltyLedgerIndex'])->middleware('permission:members.manage');
        Route::get('crm/loyalty/redemptions', [CustomerController::class, 'crmRedemptionsIndex'])->middleware('permission:members.manage');
        Route::post('crm/loyalty/redemptions', [CustomerController::class, 'crmRedeem'])->middleware('permission:members.manage');
        Route::get('crm/dashboard', [CrmMetricsController::class, 'index'])->middleware('permission:members.manage');
        Route::get('monitoring/metrics', [MonitoringMetricsController::class, 'index'])->middleware('permission:pos.use');
        Route::get('dashboard/summary', [DashboardSummaryController::class, 'index'])->middleware('permission:pos.use');
        Route::get('kitchen/tickets', [KitchenTicketController::class, 'index'])->middleware('permission:pos.use');
        Route::patch('kitchen/tickets/{ticket}/status', [KitchenTicketController::class, 'updateStatus'])->middleware('permission:pos.use');
        Route::get('print/profiles', [PrinterProfileController::class, 'index'])->middleware('permission:settings.view');
        Route::post('print/profiles', [PrinterProfileController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('print/profiles/{profile}', [PrinterProfileController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('print/profiles/{profile}', [PrinterProfileController::class, 'destroy'])->middleware('permission:settings.update');
        Route::get('print/routes', [PrinterRouteController::class, 'index'])->middleware('permission:settings.view');
        Route::post('print/routes', [PrinterRouteController::class, 'store'])->middleware('permission:settings.update');
        Route::delete('print/routes/{route}', [PrinterRouteController::class, 'destroy'])->middleware('permission:settings.update');
        Route::get('print/queue/status', [PrintQueueController::class, 'status'])->middleware('permission:pos.use');
        Route::post('print/queue/jobs/{printJob}/retry', [PrintQueueController::class, 'retry'])->middleware('permission:pos.use');
        Route::get('print/receipt-layouts', [ReceiptLayoutsController::class, 'index'])->middleware('permission:settings.view');
        Route::post('print/receipt-layouts', [ReceiptLayoutsController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('print/receipt-layouts/{template}', [ReceiptLayoutsController::class, 'update'])->middleware('permission:settings.update');
        Route::post('print/documents/render', [ReceiptDocumentController::class, 'render'])->middleware('permission:pos.use');
        Route::post('print/documents/cashier-session-summary', [ReceiptDocumentController::class, 'cashierSessionSummary'])->middleware('permission:pos.use');
        Route::get('print/documents/history', [ReceiptDocumentController::class, 'history'])->middleware('permission:pos.use');
        Route::get('print/documents/{history}', [ReceiptDocumentController::class, 'show'])->middleware('permission:pos.use');
        Route::get('print/documents/{history}/pdf', [ReceiptDocumentController::class, 'pdf'])->middleware('permission:pos.use');
        Route::post('print/documents/{history}/reprint', [ReceiptDocumentController::class, 'reprint'])->middleware('permission:pos.use');
        Route::post('print/documents/{history}/defer', [ReceiptDocumentController::class, 'markDeferred'])->middleware('permission:pos.use');
        Route::get('qr-orders', [QrOrderController::class, 'index'])->middleware('permission:pos.use');
        Route::post('qr-orders/{qrOrderRequest}/confirm', [QrOrderController::class, 'confirm'])->middleware('permission:pos.use');
        Route::post('qr-orders/{qrOrderRequest}/reject', [QrOrderController::class, 'reject'])->middleware('permission:pos.use');

        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.manage');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.manage');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.manage');
        Route::patch('suppliers/{supplier}/status', [SupplierController::class, 'updateStatus'])->middleware('permission:suppliers.manage');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers.manage');

        Route::apiResource('purchase-requests', PurchaseRequestController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('goods-receipts', GoodsReceiptController::class)->only(['index', 'store']);
        Route::apiResource('purchase-invoices', PurchaseInvoiceController::class)->only(['index', 'store', 'update']);
        Route::post('purchase-invoices/{purchaseInvoice}/payments', [PurchaseInvoiceController::class, 'addPayment']);
    });
});

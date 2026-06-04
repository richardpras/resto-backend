<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\AccountingPeriodController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\ReportController;
use App\Modules\HR\Http\Controllers\AdjustmentController;
use App\Modules\HR\Http\Controllers\AttendanceController;
use App\Modules\HR\Http\Controllers\AttendanceRecordController;
use App\Modules\HR\Http\Controllers\AttendancePeriodController;
use App\Modules\HR\Http\Controllers\AttendanceSummaryController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\EmployeeRosterController;
use App\Modules\HR\Http\Controllers\EmployeeShiftAssignmentController;
use App\Modules\HR\Http\Controllers\LeaveBalanceController;
use App\Modules\HR\Http\Controllers\LeaveRequestController;
use App\Modules\HR\Http\Controllers\LeaveTypeController;
use App\Modules\HR\Http\Controllers\BpjsConfigController;
use App\Modules\HR\Http\Controllers\BpjsProfileController;
use App\Modules\HR\Http\Controllers\CashAdvanceController;
use App\Modules\HR\Http\Controllers\PayrollAdjustmentController;
use App\Modules\HR\Http\Controllers\PayslipController;
use App\Modules\HR\Http\Controllers\EmployeeLoanController;
use App\Modules\HR\Http\Controllers\LoanController;
use App\Modules\HR\Http\Controllers\OvertimeController;
use App\Modules\HR\Http\Controllers\OvertimeRequestController;
use App\Modules\HR\Http\Controllers\OvertimeSummaryController;
use App\Modules\HR\Http\Controllers\OvertimeTypeController;
use App\Modules\HR\Http\Controllers\EmployeeSalaryProfileController;
use App\Modules\HR\Http\Controllers\PayrollPreparationPeriodController;
use App\Modules\HR\Http\Controllers\PayrollRunV2Controller;
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
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyProgramController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyProgramRuleController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyRewardController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyRewardRedemptionController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyCampaignController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyVoucherController;
use App\Modules\LoyaltyEngine\Http\Controllers\MemberSegmentController;
use App\Modules\LoyaltyEngine\Http\Controllers\MemberVoucherController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyNotificationController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyAnalyticsDashboardController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyAutomationController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyTierController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltySimulatorController;
use App\Modules\Members\Http\Controllers\MemberController;
use App\Modules\Menu\Http\Controllers\MenuItemController;
use App\Modules\Monitoring\Http\Controllers\MonitoringMetricsController;
use App\Modules\Monitoring\Http\Controllers\DashboardSummaryController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Orders\Http\Controllers\OrderVoucherController;
use App\Modules\Orders\Http\Controllers\OpenBillController;
use App\Modules\Orders\Http\Controllers\OrderItemRecoveryController;
use App\Modules\Orders\Http\Controllers\OrderItemRecoverySettlementController;
use App\Modules\Orders\Http\Controllers\PosSessionController;
use App\Modules\Orders\Http\Controllers\QrOrderController;
use App\Modules\Orders\Http\Controllers\TableMasterController;
use App\Modules\Orders\Http\Controllers\TableQrController;
use App\Modules\Reservations\Http\Controllers\ReservationController;
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
use App\Modules\Settings\Http\Controllers\OutletPaymentMethodConfigController;
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
use App\Modules\UserManagement\Http\Controllers\DepartmentController;
use App\Modules\UserManagement\Http\Controllers\OrganizationEmployeeController;
use App\Modules\UserManagement\Http\Controllers\PermissionController;
use App\Modules\UserManagement\Http\Controllers\PositionController;
use App\Modules\UserManagement\Http\Controllers\RoleController;
use App\Modules\UserManagement\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('qr-orders', [QrOrderController::class, 'store']);
    Route::post('qr-orders/{qrOrderRequest}/call-cashier', [QrOrderController::class, 'callCashier']);
    Route::get('qr/tables/{qrPublicId}', [TableQrController::class, 'resolve']);
    Route::get('qr/legacy-resolve', [TableQrController::class, 'resolveLegacy']);

    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show'])->middleware(['auth:api', 'permission:pos.use']);
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->middleware(['auth:api', 'permission:pos.use']);
    Route::patch('orders/{order}', [OrderController::class, 'update'])->middleware(['auth:api', 'permission:pos.use']);
    Route::patch('orders/{order}/member', [OrderController::class, 'setMember'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/voucher', [OrderVoucherController::class, 'apply'])->middleware(['auth:api', 'permission:pos.use']);
    Route::delete('orders/{order}/voucher', [OrderVoucherController::class, 'remove'])->middleware(['auth:api', 'permission:pos.use']);
    Route::get('orders/{order}/voucher-preview', [OrderVoucherController::class, 'preview'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/splits', [OrderController::class, 'storeSplit'])->middleware(['auth:api', 'permission:pos.use']);
    Route::patch('orders/{order}/splits/{split}', [OrderController::class, 'updateSplit'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/payments', [OrderController::class, 'addPayments'])->middleware(['auth:api', 'permission:pos.use']);
    Route::get('orders/{order}/payments', [OrderController::class, 'listPayments'])->middleware('auth:api');
    Route::get('orders/{order}/events', [OrderController::class, 'listEvents'])->middleware('auth:api');
        Route::get('open-bills/table', [OpenBillController::class, 'byTable'])->middleware('permission:pos.use');
    Route::post('payment-webhooks/{provider}', [PaymentTransactionController::class, 'webhook']);
    Route::post('payments/webhooks/xendit', [XenditInvoiceWebhookController::class, 'store']);
    Route::post('orders/shift-close', [OrderController::class, 'closeShift'])->middleware(['auth:api', 'permission:finance.shift_close']);
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

        // HRM foundation — canonical Employee master; see EmployeeMasterService.
        Route::get('hr/employees', [EmployeeController::class, 'index'])
            ->middleware('permission.any:payroll.manage,employees.view');
        Route::post('hr/employees', [EmployeeController::class, 'store'])
            ->middleware('permission.any:payroll.manage,employees.manage');
        Route::get('hr/employees/{employee}/shift-history', [EmployeeController::class, 'shiftHistory'])
            ->middleware('permission.any:payroll.manage,shift.view,employees.view');
        Route::get('hr/employees/{employee}/schedule', [EmployeeController::class, 'schedule'])
            ->middleware('permission.any:payroll.manage,schedule.view,employees.view');
        Route::get('hr/employees/{employee}/attendance', [EmployeeController::class, 'attendance'])
            ->middleware('permission.any:payroll.manage,attendance.view,employees.view');
        Route::get('hr/employees/{employee}/leave-balances', [LeaveBalanceController::class, 'index'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::patch('hr/employees/{employee}/leave-balances', [LeaveBalanceController::class, 'update'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::get('hr/employees/{employee}', [EmployeeController::class, 'show'])
            ->middleware('permission.any:payroll.manage,employees.view');
        Route::put('hr/employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission.any:payroll.manage,employees.manage');
        Route::patch('hr/employees/{employee}', [EmployeeController::class, 'update'])
            ->middleware('permission.any:payroll.manage,employees.manage');
        Route::delete('hr/employees/{employee}', [EmployeeController::class, 'destroy'])
            ->middleware('permission.any:payroll.manage,employees.manage');

        Route::get('departments', [DepartmentController::class, 'index'])
            ->middleware('permission.any:users.manage,employees.view');
        Route::post('departments', [DepartmentController::class, 'store'])
            ->middleware('permission.any:users.manage,employees.manage');
        Route::patch('departments/{department}', [DepartmentController::class, 'update'])
            ->middleware('permission.any:users.manage,employees.manage');
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])
            ->middleware('permission.any:users.manage,employees.manage');

        Route::get('positions', [PositionController::class, 'index'])
            ->middleware('permission.any:users.manage,employees.view');
        Route::post('positions', [PositionController::class, 'store'])
            ->middleware('permission.any:users.manage,employees.manage');
        Route::patch('positions/{position}', [PositionController::class, 'update'])
            ->middleware('permission.any:users.manage,employees.manage');
        Route::delete('positions/{position}', [PositionController::class, 'destroy'])
            ->middleware('permission.any:users.manage,employees.manage');

        Route::get('employees', [OrganizationEmployeeController::class, 'index'])
            ->middleware('permission.any:users.manage,employees.view');
        Route::post('employees', [OrganizationEmployeeController::class, 'store'])
            ->middleware('permission.any:users.manage,employees.manage');
        Route::get('employees/{employee}', [OrganizationEmployeeController::class, 'show'])
            ->middleware('permission.any:users.manage,employees.view');
        Route::patch('employees/{employee}', [OrganizationEmployeeController::class, 'update'])
            ->middleware('permission.any:users.manage,employees.manage');
        Route::patch('employees/{employee}/assign-user', [OrganizationEmployeeController::class, 'assignUser'])
            ->middleware('permission.any:users.manage,employees.manage');
        Route::patch('employees/{employee}/remove-user', [OrganizationEmployeeController::class, 'removeUser'])
            ->middleware('permission.any:users.manage,employees.manage');

        Route::get('shifts', [ShiftController::class, 'index'])
            ->middleware('permission.any:payroll.manage,attendance.view');
        Route::post('shifts', [ShiftController::class, 'store'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::put('shifts/{shift}', [ShiftController::class, 'update'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::delete('shifts/{shift}', [ShiftController::class, 'destroy'])
            ->middleware('permission.any:payroll.manage,attendance.manage');

        Route::get('shift-assignments', [EmployeeShiftAssignmentController::class, 'index'])
            ->middleware('permission.any:payroll.manage,shift.view');
        Route::post('shift-assignments', [EmployeeShiftAssignmentController::class, 'store'])
            ->middleware('permission.any:payroll.manage,shift.manage');
        Route::get('shift-assignments/{shiftAssignment}', [EmployeeShiftAssignmentController::class, 'show'])
            ->middleware('permission.any:payroll.manage,shift.view');
        Route::patch('shift-assignments/{shiftAssignment}', [EmployeeShiftAssignmentController::class, 'update'])
            ->middleware('permission.any:payroll.manage,shift.manage');
        Route::patch('shift-assignments/{shiftAssignment}/deactivate', [EmployeeShiftAssignmentController::class, 'deactivate'])
            ->middleware('permission.any:payroll.manage,shift.manage');

        Route::get('rosters', [EmployeeRosterController::class, 'index'])
            ->middleware('permission.any:payroll.manage,schedule.view');
        Route::post('rosters', [EmployeeRosterController::class, 'store'])
            ->middleware('permission.any:payroll.manage,schedule.manage');
        Route::patch('rosters/{roster}', [EmployeeRosterController::class, 'update'])
            ->middleware('permission.any:payroll.manage,schedule.manage');
        Route::delete('rosters/{roster}', [EmployeeRosterController::class, 'destroy'])
            ->middleware('permission.any:payroll.manage,schedule.manage');
        Route::post('rosters/generate', [EmployeeRosterController::class, 'generate'])
            ->middleware('permission.any:payroll.manage,schedule.manage');
        Route::post('rosters/copy', [EmployeeRosterController::class, 'copy'])
            ->middleware('permission.any:payroll.manage,schedule.manage');
        Route::post('rosters/publish', [EmployeeRosterController::class, 'publish'])
            ->middleware('permission.any:payroll.manage,schedule.manage');

        Route::get('attendances', [AttendanceController::class, 'index'])
            ->middleware('permission.any:payroll.manage,attendance.view');
        Route::post('attendances/sync', [AttendanceController::class, 'sync'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::post('attendances/{attendance}/manual-correction', [AttendanceController::class, 'manualCorrection'])
            ->middleware('permission.any:payroll.manage,attendance.manage');

        Route::get('attendance/import-batches', [AttendanceRecordController::class, 'importBatches'])
            ->middleware('permission.any:payroll.manage,attendance.view');
        Route::post('attendance/import', [AttendanceRecordController::class, 'import'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::get('attendance/periods', [AttendancePeriodController::class, 'index'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::post('attendance/periods', [AttendancePeriodController::class, 'store'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::patch('attendance/periods/{period}/approve', [AttendancePeriodController::class, 'approve'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::patch('attendance/periods/{period}/lock', [AttendancePeriodController::class, 'lock'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::patch('attendance/periods/{period}/reopen', [AttendancePeriodController::class, 'reopen'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::get('attendance/summaries', [AttendanceSummaryController::class, 'index'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::get('attendance/summaries/{summary}', [AttendanceSummaryController::class, 'show'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::post('attendance/summaries/{summary}/review', [AttendanceSummaryController::class, 'review'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::get('attendance/payroll-preparation', [AttendanceSummaryController::class, 'payrollPreparation'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::get('attendance', [AttendanceRecordController::class, 'index'])
            ->middleware('permission.any:payroll.manage,attendance.view');
        Route::get('attendance/{attendance}', [AttendanceRecordController::class, 'show'])
            ->middleware('permission.any:payroll.manage,attendance.view');
        Route::patch('attendance/{attendance}', [AttendanceRecordController::class, 'update'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::post('attendance', [AttendanceController::class, 'store'])
            ->middleware('permission.any:payroll.manage,attendance.manage');
        Route::delete('attendance/{attendance}', [AttendanceController::class, 'destroy'])
            ->middleware('permission.any:payroll.manage,attendance.manage');

        Route::get('leave-types', [LeaveTypeController::class, 'index'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::post('leave-types', [LeaveTypeController::class, 'store'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::patch('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])
            ->middleware('permission.any:payroll.manage,leave.manage');

        Route::get('leave-requests', [LeaveRequestController::class, 'index'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::post('leave-requests', [LeaveRequestController::class, 'store'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::get('leave-requests/{leaveRequest}', [LeaveRequestController::class, 'show'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::patch('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::patch('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::patch('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])
            ->middleware('permission.any:payroll.manage,leave.manage');

        Route::get('employees/{employee}/leave-balances', [LeaveBalanceController::class, 'index'])
            ->middleware('permission.any:payroll.manage,leave.manage');
        Route::patch('employees/{employee}/leave-balances', [LeaveBalanceController::class, 'update'])
            ->middleware('permission.any:payroll.manage,leave.manage');

        Route::get('overtime-types', [OvertimeTypeController::class, 'index'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::post('overtime-types', [OvertimeTypeController::class, 'store'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::patch('overtime-types/{overtimeType}', [OvertimeTypeController::class, 'update'])
            ->middleware('permission.any:payroll.manage,overtime.manage');

        Route::get('overtime-requests', [OvertimeRequestController::class, 'index'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::post('overtime-requests', [OvertimeRequestController::class, 'store'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::get('overtime-requests/{overtimeRequest}', [OvertimeRequestController::class, 'show'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::patch('overtime-requests/{overtimeRequest}/approve', [OvertimeRequestController::class, 'approve'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::patch('overtime-requests/{overtimeRequest}/reject', [OvertimeRequestController::class, 'reject'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::patch('overtime-requests/{overtimeRequest}/cancel', [OvertimeRequestController::class, 'cancel'])
            ->middleware('permission.any:payroll.manage,overtime.manage');

        Route::get('overtime-summaries', [OvertimeSummaryController::class, 'index'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::get('overtime-summaries/{summary}', [OvertimeSummaryController::class, 'show'])
            ->middleware('permission.any:payroll.manage,overtime.manage');

        Route::get('payroll-preparation-periods', [PayrollPreparationPeriodController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('payroll-preparation-periods', [PayrollPreparationPeriodController::class, 'store'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::patch('payroll-preparation-periods/{period}/approve', [PayrollPreparationPeriodController::class, 'approve'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::patch('payroll-preparation-periods/{period}/lock', [PayrollPreparationPeriodController::class, 'lock'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('payroll-preparation-periods/{period}/generate', [PayrollPreparationPeriodController::class, 'generate'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::get('payroll-preparation-periods/{period}/snapshots', [PayrollPreparationPeriodController::class, 'snapshots'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::get('payroll-preparation-periods/{period}/summary', [PayrollPreparationPeriodController::class, 'summary'])
            ->middleware('permission.any:payroll.manage,payroll.create');

        Route::get('salary-profiles', [EmployeeSalaryProfileController::class, 'index'])
            ->middleware('permission.any:payroll.manage');
        Route::post('salary-profiles', [EmployeeSalaryProfileController::class, 'store'])
            ->middleware('permission.any:payroll.manage');
        Route::patch('salary-profiles/{profile}', [EmployeeSalaryProfileController::class, 'update'])
            ->middleware('permission.any:payroll.manage');

        Route::get('payroll-runs-v2', [PayrollRunV2Controller::class, 'index'])
            ->middleware('permission.any:payroll.manage');
        Route::post('payroll-runs-v2', [PayrollRunV2Controller::class, 'store'])
            ->middleware('permission.any:payroll.manage');
        Route::get('payroll-runs-v2/{run}', [PayrollRunV2Controller::class, 'show'])
            ->middleware('permission.any:payroll.manage');
        Route::patch('payroll-runs-v2/{run}/calculate', [PayrollRunV2Controller::class, 'calculate'])
            ->middleware('permission.any:payroll.manage');
        Route::patch('payroll-runs-v2/{run}/approve', [PayrollRunV2Controller::class, 'approve'])
            ->middleware('permission.any:payroll.manage');
        Route::patch('payroll-runs-v2/{run}/finalize', [PayrollRunV2Controller::class, 'finalize'])
            ->middleware('permission.any:payroll.manage');
        Route::get('payroll-runs-v2/{run}/items', [PayrollRunV2Controller::class, 'items'])
            ->middleware('permission.any:payroll.manage');

        Route::get('payslips', [PayslipController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('payslips/generate', [PayslipController::class, 'generate'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::get('employees/{employee}/payslips', [PayslipController::class, 'forEmployee'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::get('payslips/{payslip}/download', [PayslipController::class, 'download'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('payslips/{payslip}/publish', [PayslipController::class, 'publish'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('payslips/{payslip}/regenerate', [PayslipController::class, 'regenerate'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::get('payslips/{payslip}', [PayslipController::class, 'show'])
            ->middleware('permission.any:payroll.manage,payroll.create');

        Route::get('overtime', [OvertimeController::class, 'index'])
            ->middleware('permission.any:payroll.manage,overtime.view');
        Route::post('overtime', [OvertimeController::class, 'store'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::patch('overtime/{overtime}', [OvertimeController::class, 'update'])
            ->middleware('permission.any:payroll.manage,overtime.manage');
        Route::delete('overtime/{overtime}', [OvertimeController::class, 'destroy'])
            ->middleware('permission.any:payroll.manage,overtime.manage');

        Route::get('payroll-adjustments', [PayrollAdjustmentController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('payroll-adjustments', [PayrollAdjustmentController::class, 'store'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::get('payroll-adjustments/{payrollAdjustment}', [PayrollAdjustmentController::class, 'show'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::patch('payroll-adjustments/{payrollAdjustment}', [PayrollAdjustmentController::class, 'update'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::patch('payroll-adjustments/{payrollAdjustment}/approve', [PayrollAdjustmentController::class, 'approve'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::patch('payroll-adjustments/{payrollAdjustment}/cancel', [PayrollAdjustmentController::class, 'cancel'])
            ->middleware('permission.any:payroll.manage,payroll.create');

        Route::get('adjustments', [AdjustmentController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::post('adjustments', [AdjustmentController::class, 'store'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::delete('adjustments/{adjustment}', [AdjustmentController::class, 'destroy'])
            ->middleware('permission.any:payroll.manage,payroll.view');

        Route::get('cash-advances', [CashAdvanceController::class, 'index'])
            ->middleware('permission.any:payroll.manage,loans.manage,cash_advance.manage');
        Route::post('cash-advances', [CashAdvanceController::class, 'store'])
            ->middleware('permission.any:payroll.manage,loans.manage,cash_advance.manage');
        Route::get('cash-advances/{cashAdvance}', [CashAdvanceController::class, 'show'])
            ->middleware('permission.any:payroll.manage,loans.manage,cash_advance.manage');
        Route::patch('cash-advances/{cashAdvance}', [CashAdvanceController::class, 'update'])
            ->middleware('permission.any:payroll.manage,loans.manage,cash_advance.manage');
        Route::patch('cash-advances/{cashAdvance}/approve', [CashAdvanceController::class, 'approve'])
            ->middleware('permission.any:payroll.manage,loans.manage,cash_advance.manage');
        Route::patch('cash-advances/{cashAdvance}/activate', [CashAdvanceController::class, 'activate'])
            ->middleware('permission.any:payroll.manage,loans.manage,cash_advance.manage');
        Route::patch('cash-advances/{cashAdvance}/cancel', [CashAdvanceController::class, 'cancel'])
            ->middleware('permission.any:payroll.manage,loans.manage,cash_advance.manage');
        Route::get('cash-advances/{cashAdvance}/installments', [CashAdvanceController::class, 'installments'])
            ->middleware('permission.any:payroll.manage,loans.manage,cash_advance.manage');

        Route::get('bpjs-configs', [BpjsConfigController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('bpjs-configs', [BpjsConfigController::class, 'store'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::get('bpjs-profiles', [BpjsProfileController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('bpjs-profiles', [BpjsProfileController::class, 'store'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::patch('bpjs-profiles/{bpjsProfile}', [BpjsProfileController::class, 'update'])
            ->middleware('permission.any:payroll.manage,payroll.create');

        Route::get('employee-loans', [EmployeeLoanController::class, 'index'])
            ->middleware('permission.any:payroll.manage,loans.manage');
        Route::post('employee-loans', [EmployeeLoanController::class, 'store'])
            ->middleware('permission.any:payroll.manage,loans.manage');
        Route::get('employee-loans/{employeeLoan}', [EmployeeLoanController::class, 'show'])
            ->middleware('permission.any:payroll.manage,loans.manage');
        Route::patch('employee-loans/{employeeLoan}', [EmployeeLoanController::class, 'update'])
            ->middleware('permission.any:payroll.manage,loans.manage');
        Route::patch('employee-loans/{employeeLoan}/approve', [EmployeeLoanController::class, 'approve'])
            ->middleware('permission.any:payroll.manage,loans.manage');
        Route::patch('employee-loans/{employeeLoan}/activate', [EmployeeLoanController::class, 'activate'])
            ->middleware('permission.any:payroll.manage,loans.manage');
        Route::patch('employee-loans/{employeeLoan}/cancel', [EmployeeLoanController::class, 'cancel'])
            ->middleware('permission.any:payroll.manage,loans.manage');
        Route::get('employee-loans/{employeeLoan}/installments', [EmployeeLoanController::class, 'installments'])
            ->middleware('permission.any:payroll.manage,loans.manage');

        Route::get('loans', [LoanController::class, 'index'])
            ->middleware('permission.any:payroll.manage,loans.view');
        Route::post('loans', [LoanController::class, 'store'])
            ->middleware('permission.any:payroll.manage,loans.manage');
        Route::patch('loans/{loan}', [LoanController::class, 'update'])
            ->middleware('permission.any:payroll.manage,loans.manage');
        Route::delete('loans/{loan}', [LoanController::class, 'destroy'])
            ->middleware('permission.any:payroll.manage,loans.manage');

        Route::post('payroll/run', [PayrollController::class, 'run'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::get('payroll', [PayrollController::class, 'listRuns'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::get('payroll/{id}', [PayrollController::class, 'showDetail'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::post('payroll/{id}/finalize', [PayrollController::class, 'finalize'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::post('payroll/{id}/pay', [PayrollController::class, 'pay'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::post('payroll-lines/{id}/lock', [PayrollController::class, 'lockLine'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::post('payroll-lines/{id}/unlock', [PayrollController::class, 'unlockLine'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::post('payroll/{id}/post-journal', [PayrollController::class, 'postJournal'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::get('payrolls', [PayrollController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.view');
        Route::post('payrolls', [PayrollController::class, 'store'])
            ->middleware('permission.any:payroll.manage,payroll.create');

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
        Route::get('outlets/{outlet}/payment-method-configs', [OutletPaymentMethodConfigController::class, 'index'])->middleware('permission:settings.view');
        Route::put('outlets/{outlet}/payment-method-configs', [OutletPaymentMethodConfigController::class, 'sync'])->middleware('permission:settings.update');
        Route::post('outlets/{outlet}/payment-method-configs/static-qris-image', [OutletPaymentMethodConfigController::class, 'uploadStaticQrisImage'])->middleware('permission:settings.update');
        Route::get('outlets/{outlet}/payment-checkout-methods', [OutletPaymentMethodConfigController::class, 'checkoutMethods'])->middleware('permission:pos.use');

        Route::get('members/search', [MemberController::class, 'search'])->middleware('permission.any:pos.use,members.manage');
        Route::post('members/quick', [MemberController::class, 'quickStore'])->middleware('permission.any:pos.use,members.manage');
        Route::get('members/{member}/profile', [MemberController::class, 'profile'])->middleware('permission.any:pos.use,members.manage');
        Route::get('members/{member}/notifications', [LoyaltyNotificationController::class, 'indexForMember'])->middleware('permission:members.manage');
        Route::get('members/{member}/vouchers', [MemberVoucherController::class, 'indexForMember'])->middleware('permission.any:pos.use,members.manage');
        Route::post('members/{member}/redeem', [MemberController::class, 'redeem'])->middleware('permission:members.manage');
        Route::post('members/{member}/redeem-reward', [MemberController::class, 'redeemReward'])->middleware('permission:members.manage');
        Route::get('members/{member}/redemptions', [MemberController::class, 'redemptions'])->middleware('permission.any:pos.use,members.manage');
        Route::get('members', [MemberController::class, 'index'])->middleware('permission:members.manage');
        Route::post('members', [MemberController::class, 'store'])->middleware('permission:members.manage');
        Route::patch('members/{member}', [MemberController::class, 'update'])->middleware('permission:members.manage');
        Route::patch('members/{member}/status', [MemberController::class, 'updateStatus'])->middleware('permission:members.manage');
        Route::delete('members/{member}', [MemberController::class, 'destroy'])->middleware('permission:members.manage');

        Route::get('loyalty-programs/resolve-active', [LoyaltyProgramController::class, 'resolveActive'])->middleware('permission:members.manage');
        Route::get('loyalty-programs', [LoyaltyProgramController::class, 'index'])->middleware('permission:members.manage');
        Route::post('loyalty-programs', [LoyaltyProgramController::class, 'store'])->middleware('permission:members.manage');
        Route::get('loyalty-programs/{loyaltyProgram}', [LoyaltyProgramController::class, 'show'])->middleware('permission:members.manage');
        Route::patch('loyalty-programs/{loyaltyProgram}', [LoyaltyProgramController::class, 'update'])->middleware('permission:members.manage');
        Route::patch('loyalty-programs/{loyaltyProgram}/activation', [LoyaltyProgramController::class, 'setActivation'])->middleware('permission:members.manage');
        Route::get('loyalty-programs/{loyaltyProgram}/rules', [LoyaltyProgramRuleController::class, 'index'])->middleware('permission:members.manage');
        Route::post('loyalty-programs/{loyaltyProgram}/rules', [LoyaltyProgramRuleController::class, 'store'])->middleware('permission:members.manage');
        Route::patch('loyalty-program-rules/{loyaltyProgramRule}', [LoyaltyProgramRuleController::class, 'update'])->middleware('permission:members.manage');
        Route::delete('loyalty-program-rules/{loyaltyProgramRule}', [LoyaltyProgramRuleController::class, 'destroy'])->middleware('permission:members.manage');
        Route::post('loyalty-programs/simulate', [LoyaltySimulatorController::class, 'simulate'])->middleware('permission:members.manage');
        Route::get('loyalty-engine/analytics', [LoyaltySimulatorController::class, 'analytics'])->middleware('permission:members.manage');

        Route::get('loyalty-rewards', [LoyaltyRewardController::class, 'index'])->middleware('permission:members.manage');
        Route::post('loyalty-rewards', [LoyaltyRewardController::class, 'store'])->middleware('permission:members.manage');
        Route::get('loyalty-rewards/{loyaltyReward}', [LoyaltyRewardController::class, 'show'])->middleware('permission:members.manage');
        Route::patch('loyalty-rewards/{loyaltyReward}', [LoyaltyRewardController::class, 'update'])->middleware('permission:members.manage');
        Route::patch('loyalty-rewards/{loyaltyReward}/activation', [LoyaltyRewardController::class, 'setActivation'])->middleware('permission:members.manage');

        Route::get('loyalty-vouchers', [LoyaltyVoucherController::class, 'index'])->middleware('permission:members.manage');
        Route::post('loyalty-vouchers', [LoyaltyVoucherController::class, 'store'])->middleware('permission:members.manage');
        Route::get('loyalty-vouchers/{loyaltyVoucher}', [LoyaltyVoucherController::class, 'show'])->middleware('permission:members.manage');
        Route::patch('loyalty-vouchers/{loyaltyVoucher}', [LoyaltyVoucherController::class, 'update'])->middleware('permission:members.manage');
        Route::patch('loyalty-vouchers/{loyaltyVoucher}/activation', [LoyaltyVoucherController::class, 'setActivation'])->middleware('permission:members.manage');

        Route::get('member-vouchers/{memberVoucher}', [MemberVoucherController::class, 'show'])->middleware('permission:members.manage');
        Route::patch('member-vouchers/{memberVoucher}/status', [MemberVoucherController::class, 'updateStatus'])->middleware('permission:members.manage');
        Route::patch('loyalty-redemptions/{loyaltyRewardRedemption}/status', [LoyaltyRewardRedemptionController::class, 'updateStatus'])->middleware('permission:members.manage');

        Route::get('member-segments', [MemberSegmentController::class, 'index'])->middleware('permission:members.manage');
        Route::post('member-segments', [MemberSegmentController::class, 'store'])->middleware('permission:members.manage');
        Route::get('member-segments/{memberSegment}', [MemberSegmentController::class, 'show'])->middleware('permission:members.manage');
        Route::patch('member-segments/{memberSegment}', [MemberSegmentController::class, 'update'])->middleware('permission:members.manage');
        Route::patch('member-segments/{memberSegment}/activation', [MemberSegmentController::class, 'setActivation'])->middleware('permission:members.manage');
        Route::get('member-segments/{memberSegment}/preview', [MemberSegmentController::class, 'preview'])->middleware('permission:members.manage');

        Route::get('loyalty-tiers', [LoyaltyTierController::class, 'index'])->middleware('permission:members.manage');
        Route::post('loyalty-tiers', [LoyaltyTierController::class, 'store'])->middleware('permission:members.manage');
        Route::get('loyalty-tiers/{loyaltyTier}', [LoyaltyTierController::class, 'show'])->middleware('permission:members.manage');
        Route::patch('loyalty-tiers/{loyaltyTier}', [LoyaltyTierController::class, 'update'])->middleware('permission:members.manage');
        Route::patch('loyalty-tiers/{loyaltyTier}/activation', [LoyaltyTierController::class, 'setActivation'])->middleware('permission:members.manage');

        Route::get('loyalty-automations', [LoyaltyAutomationController::class, 'index'])->middleware('permission:members.manage');
        Route::post('loyalty-automations', [LoyaltyAutomationController::class, 'store'])->middleware('permission:members.manage');
        Route::get('loyalty-automations/{loyaltyAutomation}', [LoyaltyAutomationController::class, 'show'])->middleware('permission:members.manage');
        Route::patch('loyalty-automations/{loyaltyAutomation}', [LoyaltyAutomationController::class, 'update'])->middleware('permission:members.manage');
        Route::patch('loyalty-automations/{loyaltyAutomation}/activation', [LoyaltyAutomationController::class, 'setActivation'])->middleware('permission:members.manage');
        Route::get('loyalty-automations/{loyaltyAutomation}/logs', [LoyaltyAutomationController::class, 'logs'])->middleware('permission:members.manage');

        Route::get('loyalty-analytics/dashboard', [LoyaltyAnalyticsDashboardController::class, 'show'])->middleware('permission:members.manage');

        Route::patch('notifications/{notification}/read', [LoyaltyNotificationController::class, 'markRead'])->middleware('permission:members.manage');

        Route::get('loyalty-campaigns', [LoyaltyCampaignController::class, 'index'])->middleware('permission:members.manage');
        Route::post('loyalty-campaigns', [LoyaltyCampaignController::class, 'store'])->middleware('permission:members.manage');
        Route::get('loyalty-campaigns/{loyaltyCampaign}', [LoyaltyCampaignController::class, 'show'])->middleware('permission:members.manage');
        Route::patch('loyalty-campaigns/{loyaltyCampaign}', [LoyaltyCampaignController::class, 'update'])->middleware('permission:members.manage');
        Route::patch('loyalty-campaigns/{loyaltyCampaign}/status', [LoyaltyCampaignController::class, 'updateStatus'])->middleware('permission:members.manage');
        Route::get('loyalty-campaigns/{loyaltyCampaign}/audience', [LoyaltyCampaignController::class, 'audience'])->middleware('permission:members.manage');
        Route::get('loyalty-campaigns/{loyaltyCampaign}/audience-snapshot', [LoyaltyCampaignController::class, 'audienceSnapshot'])->middleware('permission:members.manage');
        Route::post('loyalty-campaigns/{loyaltyCampaign}/activate', [LoyaltyCampaignController::class, 'activate'])->middleware('permission:members.manage');
        Route::post('loyalty-campaigns/{loyaltyCampaign}/complete', [LoyaltyCampaignController::class, 'complete'])->middleware('permission:members.manage');
        Route::post('loyalty-campaigns/{loyaltyCampaign}/cancel', [LoyaltyCampaignController::class, 'cancel'])->middleware('permission:members.manage');
        Route::post('loyalty-campaigns/{loyaltyCampaign}/issue-voucher', [LoyaltyCampaignController::class, 'issueVoucher'])->middleware('permission:members.manage');

        Route::get('orders/{order}/recovery-events', [OrderItemRecoveryController::class, 'index'])->middleware('permission:orders.recovery.read');
        Route::post('orders/{order}/items/{orderItem}/recovery/report', [OrderItemRecoveryController::class, 'report'])->middleware('permission:orders.recovery.request');
        Route::post('orders/{order}/items/{orderItem}/recovery/approve', [OrderItemRecoveryController::class, 'approve'])->middleware('permission:orders.recovery.approve');
        Route::post('orders/{order}/items/{orderItem}/recovery/settlement/preview', [OrderItemRecoverySettlementController::class, 'preview'])->middleware('permission:orders.recovery.approve');
        Route::post('orders/{order}/items/{orderItem}/recovery/settlement/record', [OrderItemRecoverySettlementController::class, 'record'])->middleware('permission:orders.recovery.approve');

        Route::get('tables', [TableMasterController::class, 'index'])->middleware('permission:tables.view');
        Route::post('tables', [TableMasterController::class, 'store'])->middleware('permission:tables.manage');
        Route::patch('tables/{table}', [TableMasterController::class, 'update'])->middleware('permission:tables.manage');
        Route::delete('tables/{table}', [TableMasterController::class, 'destroy'])->middleware('permission:tables.manage');
        Route::post('tables/{table}/qr/generate', [TableQrController::class, 'generate'])->middleware('permission:tables.manage');
        Route::post('tables/{table}/qr/rotate', [TableQrController::class, 'rotate'])->middleware('permission:tables.manage');
        Route::post('tables/{table}/qr/enable', [TableQrController::class, 'enable'])->middleware('permission:tables.manage');
        Route::post('tables/{table}/qr/disable', [TableQrController::class, 'disable'])->middleware('permission:tables.manage');
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
        Route::post('payment-transactions/reconcile', [PaymentTransactionController::class, 'reconcile'])->middleware('permission:finance.reconcile');
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
        Route::get('kitchen/tickets', [KitchenTicketController::class, 'index'])->middleware('permission.any:kitchen.use,pos.use');
        Route::patch('kitchen/tickets/{ticket}/status', [KitchenTicketController::class, 'updateStatus'])->middleware('permission.any:kitchen.use,pos.use');
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
        Route::post('reservations', [ReservationController::class, 'store'])->middleware('permission:pos.use');
        Route::get('reservations', [ReservationController::class, 'index'])->middleware('permission:pos.use');
        Route::get('reservations/dashboard', [ReservationController::class, 'dashboard'])->middleware('permission:pos.use');
        Route::get('reservations/{id}/timeline', [ReservationController::class, 'timeline'])->middleware('permission:pos.use');
        Route::get('reservations/{id}', [ReservationController::class, 'show'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/confirm', [ReservationController::class, 'confirm'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/check-in', [ReservationController::class, 'checkIn'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/seat', [ReservationController::class, 'seat'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/complete', [ReservationController::class, 'complete'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/cancel', [ReservationController::class, 'cancel'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/mark-no-show', [ReservationController::class, 'markNoShow'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/allocate-table', [ReservationController::class, 'allocateTable'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/unallocate-table', [ReservationController::class, 'unallocateTable'])->middleware('permission:pos.use');
        Route::get('reservations/{id}/allocated-tables', [ReservationController::class, 'allocatedTables'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/start-service', [ReservationController::class, 'startService'])->middleware('permission:pos.use');

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

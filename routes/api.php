<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\AccountingHealthController;
use App\Modules\Accounting\Http\Controllers\AccountingPeriodController;
use App\Modules\Accounting\Http\Controllers\AccountingPostingFailureController;
use App\Modules\Accounting\Http\Controllers\AccountingPostingMappingController;
use App\Modules\Accounting\Http\Controllers\AccountingReconciliationController;
use App\Modules\Accounting\Http\Controllers\AccountingSettingsController;
use App\Modules\Accounting\Http\Controllers\CashFlowReportController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\ReportController;
use App\Modules\HR\Http\Controllers\AttendanceRecordController;
use App\Modules\HR\Http\Controllers\AttendancePeriodController;
use App\Modules\HR\Http\Controllers\AttendanceSummaryController;
use App\Modules\HR\Http\Controllers\EssAuthController;
use App\Modules\HR\Http\Controllers\EssDashboardController;
use App\Modules\HR\Http\Controllers\EssProfileController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\EmployeeRosterController;
use App\Modules\HR\Http\Controllers\EmployeeShiftAssignmentController;
use App\Modules\HR\Http\Controllers\LeaveBalanceController;
use App\Modules\HR\Http\Controllers\LeaveRequestController;
use App\Modules\HR\Http\Controllers\LeaveTypeController;
use App\Modules\HR\Http\Controllers\BpjsConfigController;
use App\Modules\HR\Http\Controllers\BpjsProfileController;
use App\Modules\HR\Http\Controllers\EmployeeTaxProfileController;
use App\Modules\HR\Http\Controllers\Pph21ConfigController;
use App\Modules\HR\Http\Controllers\ReimbursementController;
use App\Modules\HR\Http\Controllers\CashAdvanceController;
use App\Modules\HR\Http\Controllers\PayrollAdjustmentController;
use App\Modules\HR\Http\Controllers\PayslipController;
use App\Modules\HR\Http\Controllers\EmployeeLoanController;
use App\Modules\HR\Http\Controllers\OvertimeRequestController;
use App\Modules\HR\Http\Controllers\OvertimeSummaryController;
use App\Modules\HR\Http\Controllers\OvertimeTypeController;
use App\Modules\HR\Http\Controllers\EmployeeSalaryProfileController;
use App\Modules\HR\Http\Controllers\PayrollPreparationPeriodController;
use App\Modules\HR\Http\Controllers\PayrollClosingController;
use App\Modules\HR\Http\Controllers\PayrollPostingController;
use App\Modules\HR\Http\Controllers\PayrollRunV2Controller;
use App\Modules\HR\Http\Controllers\ShiftController;
use App\Modules\Hardware\Http\Controllers\HardwareBridgeController;
use App\Modules\Hardware\Http\Controllers\HardwarePairingController;
use App\Modules\Inventory\Http\Controllers\IngredientController;
use App\Modules\Inventory\Http\Controllers\InventoryValuationController;
use App\Modules\Inventory\Http\Controllers\InventoryConsumptionController;
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
use App\Modules\Notifications\Http\Controllers\UserNotificationController;
use App\Modules\System\Http\Controllers\AuditCenterController;
use App\Modules\System\Http\Controllers\BugReportController;
use App\Modules\System\Http\Controllers\FailedJobController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyAnalyticsDashboardController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyAutomationController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltyTierController;
use App\Modules\LoyaltyEngine\Http\Controllers\LoyaltySimulatorController;
use App\Modules\Members\Http\Controllers\MemberController;
use App\Modules\Menu\Http\Controllers\MenuCostingController;
use App\Modules\Menu\Http\Controllers\MenuAnalyticsController;
use App\Modules\Menu\Http\Controllers\MenuEngineeringController;
use App\Modules\Menu\Http\Controllers\MenuAutomationController;
use App\Modules\Menu\Http\Controllers\MenuDashboardController;
use App\Modules\Menu\Http\Controllers\MenuForecastingController;
use App\Modules\Menu\Http\Controllers\MenuOptimizationController;
use App\Modules\Menu\Http\Controllers\MenuProductionController;
use App\Modules\Menu\Http\Controllers\MenuProfitabilityController;
use App\Modules\Menu\Http\Controllers\MenuCategoryController;
use App\Modules\Menu\Http\Controllers\MenuCategoryPrinterMappingController;
use App\Modules\Menu\Http\Controllers\MenuItemController;
use App\Modules\Menu\Http\Controllers\MenuItemImageController;
use App\Modules\Menu\Http\Controllers\PublicQrMenuController;
use App\Modules\Monitoring\Http\Controllers\MonitoringMetricsController;
use App\Modules\Monitoring\Http\Controllers\DashboardSummaryController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Orders\Http\Controllers\OrderPromotionController;
use App\Modules\Orders\Http\Controllers\OrderVoucherController;
use App\Modules\Orders\Http\Controllers\OpenBillController;
use App\Modules\Orders\Http\Controllers\OrderItemRecoveryController;
use App\Modules\Orders\Http\Controllers\OrderItemRecoverySettlementController;
use App\Modules\Orders\Http\Controllers\PosSessionController;
use App\Modules\Orders\Http\Controllers\QrOrderController;
use App\Modules\Orders\Http\Controllers\QrGuestSessionPublicController;
use App\Modules\Orders\Http\Controllers\QrOrderPublicController;
use App\Modules\Orders\Http\Controllers\TableMasterController;
use App\Modules\Orders\Http\Controllers\TableQrController;
use App\Modules\Reservations\Http\Controllers\PublicReservationController;
use App\Modules\Reservations\Http\Controllers\ReservationController;
use App\Modules\Reservations\Http\Controllers\ReservationDepositController;
use App\Modules\Payments\Http\Controllers\PaymentHealthController;
use App\Modules\Payments\Http\Controllers\PaymentTransactionController;
use App\Modules\Payments\Http\Controllers\XenditSandboxSimulationController;
use App\Modules\Payments\Http\Controllers\XenditInvoiceWebhookController;
use App\Modules\PromotionEngine\Http\Controllers\PromotionController;
use App\Modules\Print\Http\Controllers\PrinterProfileController;
use App\Modules\Print\Http\Controllers\PrinterRouteController;
use App\Modules\Print\Http\Controllers\PrintQueueController;
use App\Modules\Print\Http\Controllers\ReceiptDocumentController;
use App\Modules\Print\Http\Controllers\ReceiptLayoutsController;
use App\Modules\Purchase\Http\Controllers\GoodsReceiptController;
use App\Modules\Purchase\Http\Controllers\InventoryProcurementSettingController;
use App\Modules\Purchase\Http\Controllers\ProcurementAnalyticsController;
use App\Modules\Purchase\Http\Controllers\ProcurementMatchController;
use App\Modules\Purchase\Http\Controllers\ProcurementPostingController;
use App\Modules\Purchase\Http\Controllers\ProcurementPayablesController;
use App\Modules\Purchase\Http\Controllers\ProcurementSummaryController;
use App\Modules\Purchase\Http\Controllers\WarehouseController;
use App\Modules\Purchase\Http\Controllers\PurchaseInvoiceController;
use App\Modules\Purchase\Http\Controllers\SupplierPaymentController;
use App\Modules\Purchase\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\PurchaseRequestController;
use App\Modules\Settings\Http\Controllers\BankAccountSettingsCrudController;
use App\Modules\Settings\Http\Controllers\IntegrationSettingsController;
use App\Modules\Settings\Http\Controllers\MerchantSettingsController;
use App\Modules\Settings\Http\Controllers\NumberingSettingsController;
use App\Modules\Settings\Http\Controllers\OutletPaymentMethodConfigController;
use App\Modules\Settings\Http\Controllers\OutletReceiptSettingsController;
use App\Modules\Settings\Http\Controllers\OutletReservationSettingsController;
use App\Modules\Settings\Http\Controllers\OutletSettingsCrudController;
use App\Modules\Settings\Http\Controllers\OutletLogoController;
use App\Modules\Settings\Http\Controllers\PaymentMethodSettingsCrudController;
use App\Modules\Settings\Http\Controllers\PrinterSettingsCrudController;
use App\Modules\Settings\Http\Controllers\SystemSettingsController;
use App\Modules\Settings\Http\Controllers\OutletTaxAssignmentsController;
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
use App\Modules\UserManagement\Http\Controllers\UserManagementAuditLogController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('hardware/pairing/redeem', [HardwarePairingController::class, 'redeem'])
        ->middleware('throttle:'.(string) config('hardware.pairing.redeem_rate_limit_per_minute', 10).',1');
    Route::post('hardware/auth/refresh', [HardwarePairingController::class, 'refresh'])
        ->middleware('throttle:30,1');

    Route::middleware('auth.hardware.bridge')->group(function (): void {
        Route::post('hardware/devices/register', [HardwareBridgeController::class, 'register']);
        Route::post('hardware/devices/heartbeat', [HardwareBridgeController::class, 'heartbeat']);
        Route::post('hardware/sessions/open', [HardwareBridgeController::class, 'openSession']);
        Route::post('hardware/sessions/{session}/close', [HardwareBridgeController::class, 'closeSession']);
        Route::get('hardware/commands/pull', [HardwareBridgeController::class, 'pullCommands']);
        Route::post('hardware/commands/{command}/ack', [HardwareBridgeController::class, 'ack']);
        Route::post('hardware/commands/{command}/nack', [HardwareBridgeController::class, 'nack']);
    });

    Route::post('auth/login', [AuthController::class, 'login']);

    Route::prefix('ess')->middleware('ess.enabled')->group(function (): void {
        Route::post('login', [EssAuthController::class, 'login']);
        Route::middleware('auth:employee_api')->group(function (): void {
            Route::post('logout', [EssAuthController::class, 'logout']);
            Route::get('me', [EssAuthController::class, 'me']);
            Route::get('dashboard', [EssDashboardController::class, 'show']);
            Route::get('profile', [EssProfileController::class, 'show']);
        });
    });
    Route::post('qr-orders', [QrOrderController::class, 'store']);
    Route::post('qr-orders/{qrOrderRequest}/call-cashier', [QrOrderController::class, 'callCashier']);
    Route::get('public/qr-orders/{orderCode}', [QrOrderPublicController::class, 'show']);
    Route::post('public/qr-orders/{orderCode}/approve-adjustments', [QrOrderPublicController::class, 'approveAdjustments']);
    Route::get('public/qr-guest-sessions/{guestSessionToken}/orders', [QrGuestSessionPublicController::class, 'orders']);
    Route::get('public/qr/tables/{qrPublicId}/menu', [PublicQrMenuController::class, 'show']);
    Route::get('public/reserve/{outletSlug}', [PublicReservationController::class, 'showOutlet'])->middleware('throttle:60,1');
    Route::get('public/reserve/{outletSlug}/menu', [PublicReservationController::class, 'menu'])->middleware('throttle:60,1');
    Route::post('public/reserve/{outletSlug}', [PublicReservationController::class, 'store'])->middleware('throttle:20,1');
    Route::get('public/reservations/{reservationCode}', [PublicReservationController::class, 'show'])->middleware('throttle:60,1');
    Route::post('public/reservations/{reservationCode}/deposit-proof', [PublicReservationController::class, 'submitDepositProof'])->middleware('throttle:10,1');
    Route::get('public/menu-images/{menuItem}', [MenuItemImageController::class, 'serve'])->whereNumber('menuItem');
    Route::get('public/outlet-logos/{outlet}', [OutletLogoController::class, 'serve'])->whereNumber('outlet');
    Route::get('qr/tables/{qrPublicId}', [TableQrController::class, 'resolve']);
    Route::get('qr/legacy-resolve', [TableQrController::class, 'resolveLegacy']);

    Route::get('orders/next-code', [OrderController::class, 'nextCode'])->middleware(['auth:api', 'permission:pos.use']);
    Route::get('orders/recovery-pending-count', [OrderItemRecoveryController::class, 'recoveryPendingCount'])->middleware(['auth:api', 'permission:orders.recovery.read']);
    Route::get('orders/recovery-summary', [\App\Modules\Orders\Http\Controllers\OrderRecoveryReportingController::class, 'summary'])->middleware(['auth:api', 'permission:orders.recovery.read']);
    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show'])->middleware(['auth:api', 'permission:pos.use']);
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])->middleware(['auth:api', 'permission:pos.use']);
    Route::patch('orders/{order}', [OrderController::class, 'update'])->middleware(['auth:api', 'permission:pos.use']);
    Route::patch('orders/{order}/member', [OrderController::class, 'setMember'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/voucher', [OrderVoucherController::class, 'apply'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/voucher/by-code', [OrderVoucherController::class, 'applyByCode'])->middleware(['auth:api', 'permission:pos.use']);
    Route::delete('orders/{order}/voucher', [OrderVoucherController::class, 'remove'])->middleware(['auth:api', 'permission:pos.use']);
    Route::get('orders/{order}/voucher-preview', [OrderVoucherController::class, 'preview'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/promotions', [OrderPromotionController::class, 'apply'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/promotions/by-code', [OrderPromotionController::class, 'applyByCode'])->middleware(['auth:api', 'permission:pos.use']);
    Route::delete('orders/{order}/promotions', [OrderPromotionController::class, 'remove'])->middleware(['auth:api', 'permission:pos.use']);
    Route::get('orders/{order}/promotion-preview', [OrderPromotionController::class, 'preview'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('promotions/evaluate', [PromotionController::class, 'evaluate'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/splits', [OrderController::class, 'storeSplit'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/splits/sync', [OrderController::class, 'syncSplits'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/kitchen-reprint', [OrderController::class, 'kitchenReprint'])->middleware(['auth:api', 'permission:pos.use']);
    Route::patch('orders/{order}/splits/{split}', [OrderController::class, 'updateSplit'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('orders/{order}/payments', [OrderController::class, 'addPayments'])->middleware(['auth:api', 'permission:pos.use']);
    Route::get('pos/bootstrap', [\App\Modules\Orders\Http\Controllers\PosBootstrapController::class, 'show'])->middleware(['auth:api', 'permission:pos.use']);
    Route::get('pos/checkout-integrity-health', [\App\Modules\Orders\Http\Controllers\PosCheckoutIntegrityController::class, 'health'])->middleware(['auth:api', 'permission.any:pos.use,settings.manage']);
    Route::get('orders/{order}/payments', [OrderController::class, 'listPayments'])->middleware('auth:api');
    Route::get('orders/{order}/events', [OrderController::class, 'listEvents'])->middleware('auth:api');
        Route::get('open-bills/table', [OpenBillController::class, 'byTable'])->middleware(['auth:api', 'permission:pos.use']);
    Route::post('payment-webhooks/{provider}', [PaymentTransactionController::class, 'webhook']);
    Route::post('payments/webhooks/xendit', [XenditInvoiceWebhookController::class, 'store']);
    Route::post('orders/shift-close', [OrderController::class, 'closeShift'])->middleware(['auth:api', 'permission:finance.shift_close']);
    Route::prefix('shift-close')->middleware(['auth:api', 'permission:finance.shift_close'])->group(function (): void {
        Route::get('preflight', [\App\Modules\ShiftClose\Http\Controllers\ShiftCloseController::class, 'preflight']);
        Route::get('readiness', [\App\Modules\ShiftClose\Http\Controllers\ShiftCloseController::class, 'readiness']);
        Route::get('history', [\App\Modules\ShiftClose\Http\Controllers\ShiftCloseController::class, 'history']);
        Route::get('{id}/report', [\App\Modules\ShiftClose\Http\Controllers\ShiftCloseController::class, 'report']);
        Route::post('run', [\App\Modules\ShiftClose\Http\Controllers\ShiftCloseController::class, 'run']);
    });
    Route::apiResource('menu-items', MenuItemController::class)
        ->only(['index', 'store', 'show', 'update'])
        ->middleware(['auth:api', 'permission:pos.use']);
    Route::get('menu-categories', [MenuCategoryController::class, 'index'])
        ->middleware(['auth:api', 'permission:pos.use']);
    Route::post('menu-categories', [MenuCategoryController::class, 'store'])
        ->middleware(['auth:api', 'permission:menu.manage']);
    Route::put('menu-categories/{menuCategory}', [MenuCategoryController::class, 'update'])
        ->whereNumber('menuCategory')
        ->middleware(['auth:api', 'permission:menu.manage']);
    Route::get('menu-category-printer-mappings', [MenuCategoryPrinterMappingController::class, 'index'])
        ->middleware(['auth:api', 'permission:pos.use']);
    Route::post('menu-category-printer-mappings', [MenuCategoryPrinterMappingController::class, 'store'])
        ->middleware(['auth:api', 'permission:menu.manage']);
    Route::delete('menu-category-printer-mappings/{mapping}', [MenuCategoryPrinterMappingController::class, 'destroy'])
        ->whereNumber('mapping')
        ->middleware(['auth:api', 'permission:menu.manage']);
    Route::post('menu-items/{menuItem}/image', [MenuItemImageController::class, 'upload'])
        ->whereNumber('menuItem')
        ->middleware(['auth:api', 'permission:menu.manage']);
    Route::delete('menu-items/{menuItem}/image', [MenuItemImageController::class, 'destroy'])
        ->whereNumber('menuItem')
        ->middleware(['auth:api', 'permission:menu.manage']);

    Route::prefix('menu-costing')->middleware(['auth:api', 'permission:pos.use'])->group(function (): void {
        Route::get('menu-items/{menuItem}/breakdown', [MenuCostingController::class, 'breakdown']);
        Route::get('menu-items/{menuItem}/history', [MenuCostingController::class, 'history']);
        Route::get('menu-items/{menuItem}/food-cost', [MenuCostingController::class, 'foodCost']);
        Route::post('menu-items/{menuItem}/recalculate', [MenuCostingController::class, 'recalculate'])
            ->middleware('permission:menu.manage');
    });

    Route::prefix('menu-profitability')->middleware(['auth:api', 'permission:foodcost.view'])->group(function (): void {
        Route::get('menu-items', [MenuProfitabilityController::class, 'index']);
        Route::get('menu-items/{menuItem}', [MenuProfitabilityController::class, 'show']);
        Route::get('menu-items/{menuItem}/history', [MenuProfitabilityController::class, 'history']);
        Route::post('menu-items/{menuItem}/simulate', [MenuProfitabilityController::class, 'simulate'])
            ->middleware('permission:menu.manage');
    });

    Route::prefix('menu-production')->middleware(['auth:api'])->group(function (): void {
        Route::get('menu-items/{menuItem}/versions', [MenuProductionController::class, 'listVersions'])
            ->middleware('permission:recipe.view');
        Route::get('menu-items/{menuItem}/versions/{versionId}', [MenuProductionController::class, 'showVersion'])
            ->middleware('permission:recipe.view');
        Route::post('menu-items/{menuItem}/versions', [MenuProductionController::class, 'createVersion'])
            ->middleware('permission:recipe.manage');
        Route::post('menu-items/{menuItem}/activate-version', [MenuProductionController::class, 'activateVersion'])
            ->middleware('permission:recipe.manage');
        Route::get('orders/{orderId}/recipe-snapshot', [MenuProductionController::class, 'orderRecipeSnapshot'])
            ->middleware('permission:recipe.view');
        Route::get('production-plan', [MenuProductionController::class, 'productionPlan'])
            ->middleware('permission:production.view');
        Route::get('prep-forecast', [MenuProductionController::class, 'prepForecast'])
            ->middleware('permission:forecast.view');
        Route::get('ingredient-demand', [MenuProductionController::class, 'ingredientDemand'])
            ->middleware('permission:production.view');
        Route::get('shortages', [MenuProductionController::class, 'shortages'])
            ->middleware('permission:production.view');
    });

    Route::prefix('menu-analytics')->middleware(['auth:api', 'permission:analytics.view'])->group(function (): void {
        Route::get('executive', [MenuAnalyticsController::class, 'executive']);
        Route::get('food-cost', [MenuAnalyticsController::class, 'foodCost']);
        Route::get('food-cost/trend', [MenuAnalyticsController::class, 'foodCostTrend']);
        Route::get('food-cost/highest', [MenuAnalyticsController::class, 'foodCostHighest']);
        Route::get('food-cost/lowest', [MenuAnalyticsController::class, 'foodCostLowest']);
        Route::get('food-cost/increase-alerts', [MenuAnalyticsController::class, 'foodCostIncreaseAlerts']);
        Route::get('profitability', [MenuAnalyticsController::class, 'profitability']);
        Route::get('profitability/trend', [MenuAnalyticsController::class, 'profitabilityTrend']);
        Route::get('profitability/top-margin', [MenuAnalyticsController::class, 'profitabilityTopMargin']);
        Route::get('profitability/low-margin', [MenuAnalyticsController::class, 'profitabilityLowMargin']);
        Route::get('profitability/erosion-alerts', [MenuAnalyticsController::class, 'profitabilityErosionAlerts']);
        Route::get('production', [MenuAnalyticsController::class, 'production']);
        Route::get('production/most-produced', [MenuAnalyticsController::class, 'productionMostProduced']);
        Route::get('production/least-produced', [MenuAnalyticsController::class, 'productionLeastProduced']);
        Route::get('production/yield-loss', [MenuAnalyticsController::class, 'productionYieldLoss']);
        Route::get('production/efficiency', [MenuAnalyticsController::class, 'productionEfficiency']);
        Route::get('inventory', [MenuAnalyticsController::class, 'inventory']);
        Route::get('inventory/fast-moving', [MenuAnalyticsController::class, 'inventoryFastMoving']);
        Route::get('inventory/slow-moving', [MenuAnalyticsController::class, 'inventorySlowMoving']);
        Route::get('inventory/dead-stock', [MenuAnalyticsController::class, 'inventoryDeadStock']);
        Route::get('inventory/turnover', [MenuAnalyticsController::class, 'inventoryTurnover']);
        Route::get('inventory/value-trend', [MenuAnalyticsController::class, 'inventoryValueTrend']);
        Route::post('snapshots/create', [MenuAnalyticsController::class, 'createSnapshot'])
            ->middleware('permission:analytics.manage');
    });

    Route::prefix('menu-engineering')->middleware(['auth:api', 'permission:analytics.view'])->group(function (): void {
        Route::get('matrix', [MenuEngineeringController::class, 'matrix']);
        Route::get('matrix/stars', [MenuEngineeringController::class, 'stars']);
        Route::get('matrix/puzzles', [MenuEngineeringController::class, 'puzzles']);
        Route::get('matrix/plowhorses', [MenuEngineeringController::class, 'plowhorses']);
        Route::get('matrix/dogs', [MenuEngineeringController::class, 'dogs']);
        Route::get('matrix/trends', [MenuEngineeringController::class, 'trends']);
        Route::get('matrix/menu-items/{menuItem}', [MenuEngineeringController::class, 'menuItem']);
        Route::get('matrix/menu-items/{menuItem}/history', [MenuEngineeringController::class, 'menuItemHistory']);
        Route::get('matrix/top-performers', [MenuEngineeringController::class, 'topPerformers']);
        Route::get('matrix/worst-performers', [MenuEngineeringController::class, 'worstPerformers']);
        Route::post('matrix/snapshots/create', [MenuEngineeringController::class, 'createSnapshot'])
            ->middleware('permission:analytics.manage');
    });

    Route::prefix('menu-optimization')->middleware(['auth:api', 'permission:optimization.view'])->group(function (): void {
        Route::get('recommendations', [MenuOptimizationController::class, 'recommendations']);
        Route::get('recommendations/stars', [MenuOptimizationController::class, 'stars']);
        Route::get('recommendations/puzzles', [MenuOptimizationController::class, 'puzzles']);
        Route::get('recommendations/plowhorses', [MenuOptimizationController::class, 'plowhorses']);
        Route::get('recommendations/dogs', [MenuOptimizationController::class, 'dogs']);
        Route::get('pricing', [MenuOptimizationController::class, 'pricing']);
        Route::get('pricing/opportunities', [MenuOptimizationController::class, 'pricingOpportunities']);
        Route::get('bundles', [MenuOptimizationController::class, 'bundles']);
        Route::get('bundles/top', [MenuOptimizationController::class, 'topBundles']);
        Route::get('ingredients/opportunities', [MenuOptimizationController::class, 'ingredientOpportunities']);
        Route::get('yield/opportunities', [MenuOptimizationController::class, 'yieldOpportunities']);
        Route::post('simulate-price', [MenuOptimizationController::class, 'simulatePrice']);
        Route::post('simulate-recipe', [MenuOptimizationController::class, 'simulateRecipe']);
        Route::post('simulate-yield', [MenuOptimizationController::class, 'simulateYield']);
        Route::get('snapshots', [MenuOptimizationController::class, 'snapshots']);
        Route::post('snapshots/create', [MenuOptimizationController::class, 'createSnapshot'])
            ->middleware('permission:optimization.manage');
    });

    Route::prefix('menu-automation')->middleware(['auth:api', 'permission:automation.view'])->group(function (): void {
        Route::get('alerts', [MenuAutomationController::class, 'alerts']);
        Route::get('alerts/open', [MenuAutomationController::class, 'openAlerts']);
        Route::get('alerts/critical', [MenuAutomationController::class, 'criticalAlerts']);
        Route::get('alerts/history', [MenuAutomationController::class, 'alertHistory']);
        Route::get('rules', [MenuAutomationController::class, 'rules']);
        Route::post('rules', [MenuAutomationController::class, 'storeRule'])
            ->middleware('permission:automation.manage');
        Route::put('rules/{id}', [MenuAutomationController::class, 'updateRule'])
            ->middleware('permission:automation.manage');
        Route::delete('rules/{id}', [MenuAutomationController::class, 'destroyRule'])
            ->middleware('permission:automation.manage');
        Route::post('alerts/{id}/resolve', [MenuAutomationController::class, 'resolveAlert'])
            ->middleware('permission:automation.manage');
        Route::get('notifications', [MenuAutomationController::class, 'notifications']);
        Route::get('dashboard-summary', [MenuAutomationController::class, 'dashboardSummary']);
        Route::get('snapshots', [MenuAutomationController::class, 'snapshots']);
        Route::post('snapshots/create', [MenuAutomationController::class, 'createSnapshot'])
            ->middleware('permission:automation.manage');
        Route::get('escalations', [MenuAutomationController::class, 'escalations']);
        Route::post('escalations/run', [MenuAutomationController::class, 'runEscalations'])
            ->middleware('permission:automation.manage');
    });

    Route::prefix('menu-forecasting')->middleware(['auth:api', 'permission:forecasting.view'])->group(function (): void {
        Route::get('demand', [MenuForecastingController::class, 'demand']);
        Route::get('revenue', [MenuForecastingController::class, 'revenue']);
        Route::get('food-cost', [MenuForecastingController::class, 'foodCost']);
        Route::get('ingredients', [MenuForecastingController::class, 'ingredients']);
        Route::get('production', [MenuForecastingController::class, 'production']);
        Route::get('stock-risk', [MenuForecastingController::class, 'stockRisk']);
        Route::get('menu-items/{menuItem}', [MenuForecastingController::class, 'menuItem']);
        Route::get('summary', [MenuForecastingController::class, 'summary']);
        Route::get('snapshots', [MenuForecastingController::class, 'snapshots']);
        Route::post('snapshots/create', [MenuForecastingController::class, 'createSnapshot'])
            ->middleware('permission:forecasting.manage');
    });

    Route::prefix('menu-dashboard')->middleware(['auth:api', 'permission:dashboard.view'])->group(function (): void {
        Route::get('intelligence', [MenuDashboardController::class, 'intelligence']);
        Route::get('summary', [MenuDashboardController::class, 'summary']);
        Route::get('kpis', [MenuDashboardController::class, 'kpis']);
        Route::get('engineering', [MenuDashboardController::class, 'engineering']);
        Route::get('optimization', [MenuDashboardController::class, 'optimization']);
        Route::get('automation', [MenuDashboardController::class, 'automation']);
        Route::get('forecasting', [MenuDashboardController::class, 'forecasting']);
        Route::get('inventory', [MenuDashboardController::class, 'inventory']);
        Route::get('health', [MenuDashboardController::class, 'health']);
        Route::get('system-health', [MenuDashboardController::class, 'systemHealth']);
        Route::get('snapshots', [MenuDashboardController::class, 'snapshots']);
        Route::post('snapshots/create', [MenuDashboardController::class, 'createSnapshot'])
            ->middleware('permission:dashboard.manage');
    });

    Route::apiResource('ingredients', IngredientController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middleware(['auth:api', 'permission.any:inventory.manage,pos.use']);
    Route::get('stock-movements', [StockMovementController::class, 'index'])->middleware(['auth:api', 'permission:inventory.manage']);
    Route::post('stock-movements', [StockMovementController::class, 'store'])->middleware(['auth:api', 'permission:inventory.manage']);
    Route::get('inventory/valuations', [InventoryValuationController::class, 'index'])->middleware(['auth:api', 'permission:inventory.manage']);
    Route::post('inventory/valuations/recalculate', [InventoryValuationController::class, 'recalculate'])->middleware(['auth:api', 'permission:inventory.manage']);
    Route::get('inventory/valuations/{ingredientId}', [InventoryValuationController::class, 'show'])->middleware(['auth:api', 'permission:inventory.manage']);
    Route::get('inventory/posting-health', [InventoryConsumptionController::class, 'health'])->middleware(['auth:api', 'permission.any:inventory.manage,settings.manage']);
    Route::get('inventory/consumption/queue', [InventoryConsumptionController::class, 'index'])->middleware(['auth:api', 'permission.any:inventory.manage,settings.manage']);
    Route::post('inventory/consumption/post', [InventoryConsumptionController::class, 'post'])->middleware(['auth:api', 'permission:inventory.manage']);

    Route::middleware('auth:api')->group(function (): void {
        Route::get('notifications/unread-count', [UserNotificationController::class, 'unreadCount']);
        Route::patch('notifications/read-all', [UserNotificationController::class, 'markAllRead']);
        Route::get('notifications', [UserNotificationController::class, 'index']);
        Route::patch('notifications/{notification}/read', [UserNotificationController::class, 'markRead'])->whereNumber('notification');
    });

    Route::prefix('system')->middleware(['auth:api', 'permission:settings.manage'])->group(function (): void {
        Route::get('failed-jobs', [FailedJobController::class, 'index']);
        Route::get('failed-jobs/summary', [FailedJobController::class, 'summary']);
        Route::get('failed-jobs/trends', [FailedJobController::class, 'trends']);
    });

    Route::prefix('audit-center')->middleware(['auth:api', 'permission:settings.manage'])->group(function (): void {
        Route::get('/', [AuditCenterController::class, 'index']);
        Route::get('entity-history', [AuditCenterController::class, 'entityHistory']);
        Route::get('search', [AuditCenterController::class, 'search']);
        Route::get('summary', [AuditCenterController::class, 'summary']);
    });

    Route::post('bug-reports', [BugReportController::class, 'store'])->middleware('auth:api');

    Route::prefix('bug-reports')->middleware(['auth:api', 'permission:settings.manage'])->group(function (): void {
        Route::get('/', [BugReportController::class, 'index']);
        Route::get('{bugReport}', [BugReportController::class, 'show'])->whereNumber('bugReport');
        Route::patch('{bugReport}', [BugReportController::class, 'update'])->whereNumber('bugReport');
        Route::post('{bugReport}/comments', [BugReportController::class, 'storeComment'])->whereNumber('bugReport');
        Route::get('{bugReport}/attachments/{attachment}', [BugReportController::class, 'downloadAttachment'])
            ->whereNumber(['bugReport', 'attachment']);
    });

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
    Route::get('accounting/settings', [AccountingSettingsController::class, 'show'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::patch('accounting/settings', [AccountingSettingsController::class, 'update'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/posting-mappings', [AccountingPostingMappingController::class, 'show'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/posting-mappings/status', [AccountingPostingMappingController::class, 'status'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::patch('accounting/posting-mappings', [AccountingPostingMappingController::class, 'update'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/health', [AccountingHealthController::class, 'show'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/health/trends', [AccountingHealthController::class, 'trends'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/posting-failures', [AccountingPostingFailureController::class, 'index'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::post('accounting/posting-failures/{accountingPostingFailure}/retry', [AccountingPostingFailureController::class, 'retry'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::post('accounting/posting-failures/{accountingPostingFailure}/ignore', [AccountingPostingFailureController::class, 'ignore'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/reconciliation/ap', [AccountingReconciliationController::class, 'accountsPayable'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/reconciliation/procurement', [AccountingReconciliationController::class, 'procurement'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/reconciliation/payroll', [AccountingReconciliationController::class, 'payroll'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/reconciliation/gift-cards', [AccountingReconciliationController::class, 'giftCards'])->middleware(['auth:api', 'permission:accounting.manage']);
    Route::get('accounting/reports/cash-flow', [CashFlowReportController::class, 'show'])
        ->middleware(['auth:api', 'permission:accounting.manage', 'permission:reports.view']);
    Route::get('reports/ledger', [ReportController::class, 'ledger'])->middleware(['auth:api', 'permission:reports.view']);
    Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])->middleware(['auth:api', 'permission:reports.view']);
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware(['auth:api', 'permission:reports.view']);
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware(['auth:api', 'permission:reports.view']);
    Route::get('reports/executive-sales', [\App\Modules\Reporting\Http\Controllers\ExecutiveSalesReportController::class, 'show'])
        ->middleware(['auth:api', 'permission:reports.view']);

    Route::middleware('auth:api')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/refresh', [AuthController::class, 'refresh']);
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
        Route::delete('attendance/periods/{period}', [AttendancePeriodController::class, 'destroy'])
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
        Route::post('attendance', [AttendanceRecordController::class, 'store'])
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
        Route::delete('payroll-preparation-periods/{period}', [PayrollPreparationPeriodController::class, 'destroy'])
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
        Route::patch('payroll-runs-v2/{run}/reject', [PayrollRunV2Controller::class, 'reject'])
            ->middleware('permission:payroll.manage');
        Route::patch('payroll-runs-v2/{run}/approve', [PayrollRunV2Controller::class, 'approve'])
            ->middleware('permission.any:payroll.manage');
        Route::patch('payroll-runs-v2/{run}/finalize', [PayrollRunV2Controller::class, 'finalize'])
            ->middleware('permission.any:payroll.manage');
        Route::get('payroll-runs-v2/{run}/items', [PayrollRunV2Controller::class, 'items'])
            ->middleware('permission.any:payroll.manage');
        Route::get('payroll-runs-v2/{run}/closing-summary', [PayrollClosingController::class, 'closingSummary'])
            ->middleware('permission.any:payroll.manage');
        Route::post('payroll-runs-v2/{run}/start-payment', [PayrollClosingController::class, 'startPayment'])
            ->middleware('permission.any:payroll.manage');
        Route::post('payroll-runs-v2/{run}/mark-paid', [PayrollClosingController::class, 'markPaid'])
            ->middleware('permission.any:payroll.manage');
        Route::post('payroll-runs-v2/{run}/close', [PayrollClosingController::class, 'close'])
            ->middleware('permission.any:payroll.manage');
        Route::post('payroll-runs-v2/{run}/reopen', [PayrollClosingController::class, 'reopen'])
            ->middleware('permission.any:payroll.manage');
        Route::get('payroll-runs-v2/{run}/audit', [PayrollClosingController::class, 'audit'])
            ->middleware('permission.any:payroll.manage');
        Route::get('payroll-runs-v2/{run}/posting-preview', [PayrollPostingController::class, 'preview'])
            ->middleware('permission.any:payroll.manage');
        Route::post('payroll-runs-v2/{run}/post', [PayrollPostingController::class, 'post'])
            ->middleware('permission.any:payroll.manage');
        Route::post('payroll-runs-v2/{run}/reverse-posting', [PayrollPostingController::class, 'reverse'])
            ->middleware('permission.any:payroll.manage');
        Route::get('payroll-runs-v2/{run}/posting', [PayrollPostingController::class, 'status'])
            ->middleware('permission.any:payroll.manage');

        Route::get('payslips', [PayslipController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('payslips/generate', [PayslipController::class, 'generate'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::get('payslips/generation-status', [PayslipController::class, 'generationStatus'])
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

        Route::get('pph21-configs', [Pph21ConfigController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('pph21-configs', [Pph21ConfigController::class, 'store'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::patch('pph21-configs/{pph21Config}', [Pph21ConfigController::class, 'update'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::get('employee-tax-profiles', [EmployeeTaxProfileController::class, 'index'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::post('employee-tax-profiles', [EmployeeTaxProfileController::class, 'store'])
            ->middleware('permission.any:payroll.manage,payroll.create');
        Route::patch('employee-tax-profiles/{employeeTaxProfile}', [EmployeeTaxProfileController::class, 'update'])
            ->middleware('permission.any:payroll.manage,payroll.create');

        Route::get('reimbursements', [ReimbursementController::class, 'index'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::post('reimbursements', [ReimbursementController::class, 'store'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::get('reimbursements/{reimbursement}', [ReimbursementController::class, 'show'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::patch('reimbursements/{reimbursement}', [ReimbursementController::class, 'update'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::delete('reimbursements/{reimbursement}', [ReimbursementController::class, 'destroy'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::post('reimbursements/{reimbursement}/submit', [ReimbursementController::class, 'submit'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::post('reimbursements/{reimbursement}/approve', [ReimbursementController::class, 'approve'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::post('reimbursements/{reimbursement}/reject', [ReimbursementController::class, 'reject'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::post('reimbursements/{reimbursement}/cancel', [ReimbursementController::class, 'cancel'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::post('reimbursements/{reimbursement}/attachments', [ReimbursementController::class, 'storeAttachment'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');
        Route::delete('reimbursements/attachments/{attachment}', [ReimbursementController::class, 'destroyAttachment'])
            ->middleware('permission.any:reimbursement.manage,payroll.manage');

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

        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
        Route::put('users/{user}/screen-pin', [UserController::class, 'adminSetScreenPin'])->middleware('permission:users.assign_roles');
        Route::delete('users/{user}/screen-pin', [UserController::class, 'adminClearScreenPin'])->middleware('permission:users.assign_roles');
        Route::post('users/{user}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.assign_roles');

        Route::get('user-management/audit-logs', [UserManagementAuditLogController::class, 'index'])
            ->middleware('permission:users.view');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission.any:roles.view,users.assign_roles');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::post('roles/{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:roles.assign_permissions');

        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:permissions.view');
        Route::post('permissions', [PermissionController::class, 'store'])->middleware('permission:permissions.create');

        Route::get('merchant-settings', [MerchantSettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::patch('merchant-settings', [MerchantSettingsController::class, 'update'])->middleware('permission:merchant.manage');

        Route::get('outlets', [OutletSettingsCrudController::class, 'index'])->middleware('permission:settings.view');
        Route::post('outlets', [OutletSettingsCrudController::class, 'store'])->middleware('permission:settings.manage');
        Route::patch('outlets/{outletId}', [OutletSettingsCrudController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('outlets/{outletId}', [OutletSettingsCrudController::class, 'destroy'])->middleware('permission:settings.manage');
        Route::post('outlets/{outletId}/logo', [OutletLogoController::class, 'upload'])->middleware('permission:settings.update');
        Route::delete('outlets/{outletId}/logo', [OutletLogoController::class, 'destroy'])->middleware('permission:settings.update');
        Route::get('outlets/{outletId}/reservation-settings', [OutletReservationSettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::patch('outlets/{outletId}/reservation-settings', [OutletReservationSettingsController::class, 'update'])->middleware('permission:settings.update');

        Route::get('taxes', [TaxSettingsCrudController::class, 'index'])->middleware('permission:settings.view');
        Route::post('taxes', [TaxSettingsCrudController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('taxes/{taxId}', [TaxSettingsCrudController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('taxes/{taxId}', [TaxSettingsCrudController::class, 'destroy'])->middleware('permission:settings.update');
        Route::get('outlets/{outletId}/tax-assignments', [OutletTaxAssignmentsController::class, 'show'])->middleware('permission:settings.view');
        Route::put('outlets/{outletId}/tax-assignments', [OutletTaxAssignmentsController::class, 'update'])->middleware('permission:settings.update');

        Route::get('printers', [PrinterSettingsCrudController::class, 'index'])->middleware('permission:settings.view');
        Route::post('printers', [PrinterSettingsCrudController::class, 'store'])->middleware('permission:settings.update');
        Route::post('printers/{printerId}/test', [PrinterSettingsCrudController::class, 'test'])->middleware('permission:settings.update');
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
        Route::get('settings/customer-app-url', [\App\Modules\Settings\Http\Controllers\CustomerAppUrlController::class, 'show'])->middleware('permission:settings.view');
        Route::patch('settings/customer-app-url', [\App\Modules\Settings\Http\Controllers\CustomerAppUrlController::class, 'update'])->middleware('permission:settings.update');

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
        Route::get('members/by-loyalty-account/{loyaltyAccountId}', [MemberController::class, 'byLoyaltyAccount'])->middleware('permission:members.manage');
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

        Route::get('promotions', [PromotionController::class, 'index'])->middleware('permission:promotions.manage');
        Route::post('promotions', [PromotionController::class, 'store'])->middleware('permission:promotions.manage');
        Route::get('promotions/{promotion}', [PromotionController::class, 'show'])->middleware('permission:promotions.manage');
        Route::patch('promotions/{promotion}', [PromotionController::class, 'update'])->middleware('permission:promotions.manage');
        Route::patch('promotions/{promotion}/activation', [PromotionController::class, 'setActivation'])->middleware('permission:promotions.manage');

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

        Route::patch('member-notifications/{notification}/read', [LoyaltyNotificationController::class, 'markRead'])->middleware('permission:members.manage');

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
        Route::post('orders/{order}/items/{orderItem}/recovery/refund/execute', [OrderItemRecoveryController::class, 'executeRefund'])->middleware('permission:orders.refund.execute');

        Route::get('tables', [TableMasterController::class, 'index'])->middleware('permission:tables.view');
        Route::get('tables/qr/export', [TableQrController::class, 'export'])->middleware('permission:tables.manage');
        Route::post('tables', [TableMasterController::class, 'store'])->middleware('permission:tables.manage');
        Route::patch('tables/{table}', [TableMasterController::class, 'update'])->middleware('permission:tables.manage');
        Route::delete('tables/{table}', [TableMasterController::class, 'destroy'])->middleware('permission:tables.manage');
        Route::get('tables/{table}/qr', [TableQrController::class, 'show'])->middleware('permission:tables.view');
        Route::get('tables/{table}/qr/image', [TableQrController::class, 'image'])->middleware('permission:tables.view');
        Route::post('tables/{table}/qr/generate', [TableQrController::class, 'generate'])->middleware('permission:tables.manage');
        Route::post('tables/{table}/qr/regenerate', [TableQrController::class, 'regenerate'])->middleware('permission:tables.manage');
        Route::post('tables/{table}/qr/rotate', [TableQrController::class, 'rotate'])->middleware('permission:tables.manage');
        Route::post('tables/{table}/qr/enable', [TableQrController::class, 'enable'])->middleware('permission:tables.manage');
        Route::post('tables/{table}/qr/disable', [TableQrController::class, 'disable'])->middleware('permission:tables.manage');
        Route::post('pos-sessions/open', [PosSessionController::class, 'open'])->middleware('permission:pos.use');
        Route::get('pos-sessions/{id}/close-preview', [PosSessionController::class, 'closePreview'])->middleware('permission:pos.use');
        Route::post('pos-sessions/{id}/close', [PosSessionController::class, 'close'])->middleware('permission:pos.use');
        Route::get('pos-sessions/current', [PosSessionController::class, 'current'])->middleware('permission:pos.use');
        Route::get('payments/health', [PaymentHealthController::class, 'show'])->middleware('permission:settings.manage');
        Route::get('payments/health/trends', [PaymentHealthController::class, 'trends'])->middleware('permission:settings.manage');
        Route::get('payments/health/reliability', [PaymentHealthController::class, 'reliability'])->middleware('permission:settings.manage');
        Route::get('payments/incidents', [\App\Modules\Payments\Http\Controllers\PaymentIncidentController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('payment-transactions', [PaymentTransactionController::class, 'store'])->middleware('permission:pos.use');
        Route::post('payments/xendit/simulate-paid/{paymentId}', [XenditSandboxSimulationController::class, 'simulatePaid'])->middleware('permission:pos.use');
        Route::post('payments/xendit/simulate-provider/{paymentId}', [XenditSandboxSimulationController::class, 'simulateProvider'])->middleware('permission:pos.use');
        Route::post('terminals/register', [TerminalDeviceController::class, 'register'])->middleware('permission:pos.use');
        Route::post('terminals/heartbeat', [TerminalDeviceController::class, 'heartbeat'])->middleware('permission:pos.use');
        Route::get('terminals', [TerminalDeviceController::class, 'index'])->middleware('permission:pos.use');
        Route::post('terminals/{terminal}/disable', [TerminalDeviceController::class, 'disable'])->middleware('permission:pos.use');
        Route::post('hardware/pairing/init', [HardwarePairingController::class, 'init'])->middleware('permission:settings.update');
        Route::get('hardware/devices', [HardwareBridgeController::class, 'index'])->middleware('permission.any:pos.use,settings.view,settings.update');
        Route::get('hardware/devices/summary', [HardwareBridgeController::class, 'summary'])->middleware('permission.any:pos.use,settings.view,settings.update');
        Route::post('hardware/devices/{device}/disable', [HardwareBridgeController::class, 'disableDevice'])->middleware('permission:settings.update');
        Route::post('hardware/devices/{device}/revoke', [HardwareBridgeController::class, 'revokeDevice'])->middleware('permission:settings.update');
        Route::post('hardware/commands/enqueue', [HardwareBridgeController::class, 'enqueueCommand'])->middleware('permission:pos.use');
        Route::post('sync/operations/batch', [TerminalSyncController::class, 'batch'])->middleware('permission:pos.use');
        Route::post('payment-transactions/reconcile', [PaymentTransactionController::class, 'reconcile'])->middleware('permission:finance.reconcile');
        Route::post('payment-transactions/{transaction}/expire', [PaymentTransactionController::class, 'expire'])->middleware('permission:pos.use');
        Route::get('payment-transactions/{transaction}', [PaymentTransactionController::class, 'show'])->middleware('permission:pos.use');
        Route::post('gift-cards/issue', [GiftCardController::class, 'issue'])->middleware('permission:pos.use');
        Route::get('gift-cards/{code}', [GiftCardController::class, 'check'])->middleware('permission:pos.use');
        Route::post('gift-cards/redeem', [GiftCardController::class, 'redeem'])->middleware('permission:pos.use');
        Route::post('gift-cards/settlements', [GiftCardController::class, 'settle'])->middleware('permission:pos.use');
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
        Route::get('production-stations', [ProductionStationController::class, 'index'])->middleware('permission:settings.manage');
        Route::post('production-stations', [ProductionStationController::class, 'store'])->middleware('permission:settings.manage');
        Route::put('production-stations/{productionStation}', [ProductionStationController::class, 'update'])->middleware('permission:settings.manage');
        Route::patch('production-stations/{productionStation}/status', [ProductionStationController::class, 'updateStatus'])->middleware('permission:settings.manage');
        Route::get('print/profiles', [PrinterProfileController::class, 'index'])->middleware('permission:settings.view');
        Route::post('print/profiles', [PrinterProfileController::class, 'store'])->middleware('permission:settings.update');
        Route::patch('print/profiles/{profile}', [PrinterProfileController::class, 'update'])->middleware('permission:settings.update');
        Route::delete('print/profiles/{profile}', [PrinterProfileController::class, 'destroy'])->middleware('permission:settings.update');
        Route::get('print/routes', [PrinterRouteController::class, 'index'])->middleware('permission:settings.view');
        Route::post('print/routes', [PrinterRouteController::class, 'store'])->middleware('permission:settings.update');
        Route::delete('print/routes/{route}', [PrinterRouteController::class, 'destroy'])->middleware('permission:settings.update');
        Route::get('print/queue/status', [PrintQueueController::class, 'status'])->middleware('permission.any:pos.use,settings.view,settings.update');
        Route::post('print/queue/jobs/{printJob}/retry', [PrintQueueController::class, 'retry'])->middleware('permission.any:pos.use,settings.view,settings.update');
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
        Route::get('qr-orders', [QrOrderController::class, 'index'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::get('qr-orders/pending-summary', [QrOrderController::class, 'pendingSummary'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::get('qr-orders/customer-health', [QrOrderController::class, 'customerHealth'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::get('qr-orders/search', [QrOrderController::class, 'search'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::post('qr-orders/scan', [QrOrderController::class, 'scan'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::get('qr-orders/{qrOrderRequest}/review', [QrOrderController::class, 'review'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::post('qr-orders/{qrOrderRequest}/open-in-pos', [QrOrderController::class, 'openInPos'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::get('qr-orders/{qrOrderRequest}/history', [QrOrderController::class, 'history'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::patch('qr-orders/{qrOrderRequest}/adjust', [QrOrderController::class, 'adjust'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::post('qr-orders/{qrOrderRequest}/confirm', [QrOrderController::class, 'confirm'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::post('qr-orders/{qrOrderRequest}/confirm-and-pay', [QrOrderController::class, 'confirmAndPay'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::post('qr-orders/{qrOrderRequest}/reject', [QrOrderController::class, 'reject'])->middleware('permission.any:qr_orders.view,pos.use');
        Route::post('qr-orders/{qrOrderRequest}/mark-served', [QrOrderController::class, 'markServed'])->middleware('permission.any:cashier.manage,pos.use,qr_orders.view');
        Route::get('reservations/pending-deposits', [ReservationDepositController::class, 'index'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/approve-deposit', [ReservationDepositController::class, 'approve'])->middleware('permission:pos.use');
        Route::post('reservations/{id}/reject-deposit', [ReservationDepositController::class, 'reject'])->middleware('permission:pos.use');
        Route::get('reservations/{id}/deposit-proofs/{proofId}/file', [ReservationDepositController::class, 'proofFile'])->middleware('permission:pos.use');
        Route::post('reservations', [ReservationController::class, 'store'])->middleware('permission:pos.use');
        Route::get('reservations', [ReservationController::class, 'index'])->middleware('permission:pos.use');
        Route::get('reservations/dashboard', [ReservationController::class, 'dashboard'])->middleware('permission:pos.use');
        Route::get('reservations/pos-queue', [ReservationController::class, 'posQueue'])->middleware('permission:pos.use');
        Route::get('reservations/{id}/timeline', [ReservationController::class, 'timeline'])->middleware('permission:pos.use');
        Route::get('reservations/{id}', [ReservationController::class, 'show'])->middleware('permission:pos.use');
        Route::patch('reservations/{id}', [ReservationController::class, 'update'])->middleware('permission:pos.use');
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
        Route::post('reservations/{id}/open-in-pos', [ReservationController::class, 'openInPos'])->middleware('permission:pos.use');

        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.manage');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.manage');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.manage');
        Route::patch('suppliers/{supplier}/status', [SupplierController::class, 'updateStatus'])->middleware('permission:suppliers.manage');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers.manage');

        Route::get('procurement/summary', [ProcurementSummaryController::class, 'summary'])->middleware('permission:purchase.manage');
        Route::get('procurement/payables', [ProcurementPayablesController::class, 'index'])->middleware('permission:purchase.manage');
        Route::get('procurement/ap-aging', [SupplierPaymentController::class, 'apAging'])->middleware('permission:purchase.manage');
        Route::get('procurement/supplier-statement', [SupplierPaymentController::class, 'supplierStatement'])->middleware('permission:purchase.manage');
        Route::get('procurement/match-results', [ProcurementMatchController::class, 'indexResults'])->middleware('permission:purchase.manage');
        Route::get('procurement/match-results/{invoiceId}', [ProcurementMatchController::class, 'showResult'])->middleware('permission:purchase.manage');
        Route::post('procurement/match-results/revalidate', [ProcurementMatchController::class, 'revalidate'])->middleware('permission:purchase.manage');
        Route::get('procurement/match-configs', [ProcurementMatchController::class, 'indexConfigs'])->middleware('permission:purchase.manage');
        Route::post('procurement/match-configs', [ProcurementMatchController::class, 'storeConfig'])->middleware('permission:purchase.manage');
        Route::patch('procurement/match-configs/{procurementMatchConfig}', [ProcurementMatchController::class, 'updateConfig'])->middleware('permission:purchase.manage');
        Route::get('procurement/postings', [ProcurementPostingController::class, 'index'])->middleware('permission:purchase.manage');
        Route::get('procurement/postings/status', [ProcurementPostingController::class, 'status'])->middleware('permission:purchase.manage');
        Route::get('procurement/postings/{procurementPosting}', [ProcurementPostingController::class, 'show'])->middleware('permission:purchase.manage');
        Route::post('procurement/postings/grn/{goodsReceipt}', [ProcurementPostingController::class, 'postGrn'])->middleware('permission:purchase.manage');
        Route::post('procurement/postings/invoice/{purchaseInvoice}', [ProcurementPostingController::class, 'postInvoice'])->middleware('permission:purchase.manage');
        Route::post('procurement/postings/payment/{supplierPayment}', [ProcurementPostingController::class, 'postPayment'])->middleware('permission:purchase.manage');
        Route::post('procurement/postings/{procurementPosting}/reverse', [ProcurementPostingController::class, 'reverse'])->middleware('permission:purchase.manage');
        Route::get('procurement/analytics/summary', [ProcurementAnalyticsController::class, 'summary'])->middleware('permission:purchase.manage');
        Route::get('procurement/analytics/suppliers', [ProcurementAnalyticsController::class, 'suppliers'])->middleware('permission:purchase.manage');
        Route::get('procurement/analytics/spend', [ProcurementAnalyticsController::class, 'spend'])->middleware('permission:purchase.manage');
        Route::get('procurement/analytics/payables', [ProcurementAnalyticsController::class, 'payables'])->middleware('permission:purchase.manage');
        Route::get('procurement/analytics/trends', [ProcurementAnalyticsController::class, 'trends'])->middleware('permission:purchase.manage');
        Route::get('procurement/analytics/posting', [ProcurementAnalyticsController::class, 'posting'])->middleware('permission:purchase.manage');
        Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('permission:purchase.manage');
        Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('permission:purchase.manage');
        Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('permission:purchase.manage');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('permission:purchase.manage');
        Route::get('procurement-settings', [InventoryProcurementSettingController::class, 'index'])->middleware('permission:purchase.manage');
        Route::post('procurement-settings', [InventoryProcurementSettingController::class, 'store'])->middleware('permission:purchase.manage');
        Route::patch('procurement-settings/{inventoryProcurementSetting}', [InventoryProcurementSettingController::class, 'update'])->middleware('permission:purchase.manage');
        Route::delete('procurement-settings/{inventoryProcurementSetting}', [InventoryProcurementSettingController::class, 'destroy'])->middleware('permission:purchase.manage');

        Route::get('purchase-requests', [PurchaseRequestController::class, 'index'])->middleware('permission:purchase.manage');
        Route::post('purchase-requests', [PurchaseRequestController::class, 'store'])->middleware('permission:purchase.manage');
        Route::get('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])->middleware('permission:purchase.manage');
        Route::patch('purchase-requests/{purchaseRequest}', [PurchaseRequestController::class, 'update'])->middleware('permission:purchase.manage');
        Route::post('purchase-requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->middleware('permission:purchase.manage');
        Route::post('purchase-requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])->middleware('permission:purchase.approve');
        Route::post('purchase-requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])->middleware('permission:purchase.approve');
        Route::post('purchase-requests/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel'])->middleware('permission:purchase.manage');
        Route::post('purchase-requests/{purchaseRequest}/convert', [PurchaseRequestController::class, 'convert'])->middleware('permission:purchase.manage');
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase.manage');
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase.manage');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase.manage');
        Route::patch('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchase.manage');
        Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->middleware('permission:purchase.manage');
        Route::patch('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase.manage');
        Route::patch('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchase.approve');
        Route::patch('purchase-orders/{purchaseOrder}/reject', [PurchaseOrderController::class, 'reject'])->middleware('permission:purchase.approve');
        Route::patch('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase.manage');
        Route::patch('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->middleware('permission:purchase.manage');
        Route::get('purchase-orders/{purchaseOrder}/progress', [PurchaseOrderController::class, 'progress'])->middleware('permission:purchase.manage');
        Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('permission:purchase.manage');
        Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('permission:purchase.manage');
        Route::get('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:purchase.manage');
        Route::patch('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'update'])->middleware('permission:purchase.manage');
        Route::delete('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'destroy'])->middleware('permission:purchase.manage');
        Route::patch('goods-receipts/{goodsReceipt}/receive', [GoodsReceiptController::class, 'receive'])->middleware('permission:purchase.manage');
        Route::patch('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->middleware('permission:purchase.manage');
        Route::patch('goods-receipts/{goodsReceipt}/cancel', [GoodsReceiptController::class, 'cancel'])->middleware('permission:purchase.manage');
        Route::get('goods-receipts/{goodsReceipt}/progress', [GoodsReceiptController::class, 'progress'])->middleware('permission:purchase.manage');
        Route::get('purchase-invoices', [PurchaseInvoiceController::class, 'index'])->middleware('permission:purchase.manage');
        Route::post('purchase-invoices', [PurchaseInvoiceController::class, 'store'])->middleware('permission:purchase.manage');
        Route::get('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'show'])->middleware('permission:purchase.manage');
        Route::patch('purchase-invoices/{purchaseInvoice}', [PurchaseInvoiceController::class, 'update'])->middleware('permission:purchase.manage');
        Route::patch('purchase-invoices/{purchaseInvoice}/submit', [PurchaseInvoiceController::class, 'submit'])->middleware('permission:purchase.manage');
        Route::patch('purchase-invoices/{purchaseInvoice}/approve', [PurchaseInvoiceController::class, 'approve'])->middleware('permission:purchase.approve');
        Route::patch('purchase-invoices/{purchaseInvoice}/void', [PurchaseInvoiceController::class, 'void'])->middleware('permission:purchase.manage');
        Route::get('purchase-invoices/{purchaseInvoice}/outstanding', [PurchaseInvoiceController::class, 'outstanding'])->middleware('permission:purchase.manage');
        Route::post('purchase-invoices/{purchaseInvoice}/payments', [PurchaseInvoiceController::class, 'addPayment'])->middleware('permission:purchase.manage');
        Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->middleware('permission:purchase.manage');
        Route::post('supplier-payments', [SupplierPaymentController::class, 'store'])->middleware('permission:purchase.manage');
        Route::get('supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'show'])->middleware('permission:purchase.manage');
        Route::patch('supplier-payments/{supplierPayment}', [SupplierPaymentController::class, 'update'])->middleware('permission:purchase.manage');
        Route::patch('supplier-payments/{supplierPayment}/approve', [SupplierPaymentController::class, 'approve'])->middleware('permission:purchase.approve');
        Route::patch('supplier-payments/{supplierPayment}/post', [SupplierPaymentController::class, 'post'])->middleware('permission:purchase.approve');
        Route::patch('supplier-payments/{supplierPayment}/void', [SupplierPaymentController::class, 'void'])->middleware('permission:purchase.manage');
    });
});

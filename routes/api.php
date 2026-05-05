<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\ReportController;
use App\Modules\HR\Http\Controllers\AdjustmentController;
use App\Modules\HR\Http\Controllers\AttendanceController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\LoanController;
use App\Modules\HR\Http\Controllers\OvertimeController;
use App\Modules\HR\Http\Controllers\PayrollController;
use App\Modules\HR\Http\Controllers\ShiftController;
use App\Modules\Inventory\Http\Controllers\IngredientController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Members\Http\Controllers\MemberController;
use App\Modules\Menu\Http\Controllers\MenuItemController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Suppliers\Http\Controllers\SupplierController;
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
use App\Modules\UserManagement\Http\Controllers\AuthController;
use App\Modules\UserManagement\Http\Controllers\PermissionController;
use App\Modules\UserManagement\Http\Controllers\RoleController;
use App\Modules\UserManagement\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::post('orders/{order}/payments', [OrderController::class, 'addPayments']);
    Route::post('orders/shift-close', [OrderController::class, 'closeShift']);
    Route::apiResource('menu-items', MenuItemController::class)->only(['index', 'store', 'show', 'update']);

    Route::apiResource('ingredients', IngredientController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('stock-movements', [StockMovementController::class, 'index']);
    Route::post('stock-movements', [StockMovementController::class, 'store']);

    Route::apiResource('accounts', AccountController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('journals', JournalController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('journals/{journal}/post', [JournalController::class, 'post']);
    Route::get('reports/ledger', [ReportController::class, 'ledger']);
    Route::get('reports/profit-loss', [ReportController::class, 'profitLoss']);
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet']);

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

        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.manage');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.manage');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.manage');
        Route::patch('suppliers/{supplier}/status', [SupplierController::class, 'updateStatus'])->middleware('permission:suppliers.manage');
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers.manage');
    });
});

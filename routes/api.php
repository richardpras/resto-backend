<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\HR\Http\Controllers\AttendanceController;
use App\Modules\HR\Http\Controllers\EmployeeController;
use App\Modules\HR\Http\Controllers\PayrollController;
use App\Modules\HR\Http\Controllers\ShiftController;
use App\Modules\Inventory\Http\Controllers\IngredientController;
use App\Modules\Inventory\Http\Controllers\StockMovementController;
use App\Modules\Menu\Http\Controllers\MenuItemController;
use App\Modules\Orders\Http\Controllers\OrderController;
use App\Modules\Settings\Http\Controllers\SettingsController;
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
    Route::apiResource('menu-items', MenuItemController::class)->only(['index', 'store', 'show', 'update']);

    Route::apiResource('ingredients', IngredientController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('stock-movements', [StockMovementController::class, 'index']);
    Route::post('stock-movements', [StockMovementController::class, 'store']);

    Route::apiResource('accounts', AccountController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('journals', JournalController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('journals/{journal}/post', [JournalController::class, 'post']);

    Route::middleware('auth:api')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

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

        Route::get('attendances', [AttendanceController::class, 'index']);
        Route::post('attendances/sync', [AttendanceController::class, 'sync']);
        Route::post('attendances/{attendance}/manual-correction', [AttendanceController::class, 'manualCorrection']);

        Route::get('payrolls', [PayrollController::class, 'index'])->middleware('permission:payroll.view');
        Route::post('payrolls', [PayrollController::class, 'store'])->middleware('permission:payroll.create');

        Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
        Route::post('users/{user}/roles', [UserController::class, 'assignRoles'])->middleware('permission:users.assign_roles');

        Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
        Route::post('roles/{role}/permissions', [RoleController::class, 'assignPermissions'])->middleware('permission:roles.assign_permissions');

        Route::get('permissions', [PermissionController::class, 'index'])->middleware('permission:permissions.view');
        Route::post('permissions', [PermissionController::class, 'store'])->middleware('permission:permissions.create');

        Route::get('settings', [SettingsController::class, 'show'])->middleware('permission:settings.view');
        Route::put('settings', [SettingsController::class, 'update'])->middleware('permission:settings.update');
    });
});

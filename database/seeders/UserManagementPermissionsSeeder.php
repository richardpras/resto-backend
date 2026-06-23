<?php

namespace Database\Seeders;

use App\Http\Middleware\EnsurePermission;
use App\Models\Modules\UserManagement\Domain\Permission;
use Illuminate\Database\Seeder;

/**
 * Seeds canonical user/role/permission admin gate codes used by {@see EnsurePermission}.
 */
class UserManagementPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            // User management (API gate)
            ['code' => 'users.view', 'name' => 'View users'],
            ['code' => 'users.create', 'name' => 'Create users'],
            ['code' => 'users.assign_roles', 'name' => 'Assign user roles'],
            ['code' => 'users.manage', 'name' => 'User management (web /template guard)'],
            ['code' => 'roles.view', 'name' => 'View roles'],
            ['code' => 'roles.create', 'name' => 'Create roles'],
            ['code' => 'roles.assign_permissions', 'name' => 'Assign role permissions'],
            ['code' => 'permissions.view', 'name' => 'View permissions'],
            ['code' => 'permissions.create', 'name' => 'Create permissions'],
            ['code' => 'settings.view', 'name' => 'View app settings'],
            ['code' => 'settings.update', 'name' => 'Update app settings'],
            ['code' => 'outlets.view_all', 'name' => 'View all outlets (API scope; tenant-wide settings/POS)'],
            // Web app route guards (mirror template PERMISSIONS in web/src/stores/authStore.ts)
            ['code' => 'dashboard.view_all_outlets', 'name' => 'Dashboard all outlets'],
            ['code' => 'dashboard.view_own_outlet', 'name' => 'Dashboard own outlet'],
            ['code' => 'dashboard.view', 'name' => 'View menu executive dashboard'],
            ['code' => 'dashboard.manage', 'name' => 'Manage menu dashboard snapshots'],
            ['code' => 'pos.use', 'name' => 'Use POS'],
            ['code' => 'kitchen.use', 'name' => 'Kitchen display'],
            ['code' => 'menu.manage', 'name' => 'Manage menu'],
            ['code' => 'foodcost.view', 'name' => 'View food cost and menu profitability'],
            ['code' => 'recipe.view', 'name' => 'View recipe versions'],
            ['code' => 'recipe.manage', 'name' => 'Manage recipe versions'],
            ['code' => 'production.view', 'name' => 'View production planning'],
            ['code' => 'production.manage', 'name' => 'Manage production planning'],
            ['code' => 'forecast.view', 'name' => 'View prep forecasts'],
            ['code' => 'analytics.view', 'name' => 'View menu analytics'],
            ['code' => 'analytics.manage', 'name' => 'Manage menu analytics snapshots'],
            ['code' => 'optimization.view', 'name' => 'View menu optimization recommendations'],
            ['code' => 'optimization.manage', 'name' => 'Manage menu optimization snapshots'],
            ['code' => 'automation.view', 'name' => 'View menu automation alerts'],
            ['code' => 'automation.manage', 'name' => 'Manage menu automation rules and snapshots'],
            ['code' => 'forecasting.view', 'name' => 'View menu demand forecasting'],
            ['code' => 'forecasting.manage', 'name' => 'Manage menu forecast snapshots'],
            ['code' => 'inventory.manage', 'name' => 'Manage inventory'],
            ['code' => 'purchase.manage', 'name' => 'Manage purchases'],
            ['code' => 'purchase.approve', 'name' => 'Approve purchase workflow documents'],
            ['code' => 'promotions.manage', 'name' => 'Manage promotions'],
            ['code' => 'payroll.manage', 'name' => 'Manage payroll (web)'],
            ['code' => 'payroll.view', 'name' => 'View payroll'],
            ['code' => 'payroll.create', 'name' => 'Create payroll preparation and runs'],
            ['code' => 'employees.view', 'name' => 'View employees (HRM)'],
            ['code' => 'employees.manage', 'name' => 'Manage employees (HRM)'],
            ['code' => 'attendance.view', 'name' => 'View attendance (HRM)'],
            ['code' => 'attendance.manage', 'name' => 'Manage attendance and shift templates (HRM)'],
            ['code' => 'shift.view', 'name' => 'View employee shift assignments (HRM)'],
            ['code' => 'shift.manage', 'name' => 'Manage employee shift assignments (HRM)'],
            ['code' => 'schedule.view', 'name' => 'View employee rosters / schedules (HRM)'],
            ['code' => 'schedule.manage', 'name' => 'Manage employee rosters / schedules (HRM)'],
            ['code' => 'leave.manage', 'name' => 'Manage leave types, requests, and balances (HRM)'],
            ['code' => 'overtime.view', 'name' => 'View overtime (HRM)'],
            ['code' => 'overtime.manage', 'name' => 'Manage overtime (HRM)'],
            ['code' => 'loans.view', 'name' => 'View employee loans (HRM)'],
            ['code' => 'loans.manage', 'name' => 'Manage employee loans (HRM)'],
            ['code' => 'cash_advance.manage', 'name' => 'Manage employee cash advances (HRM)'],
            ['code' => 'accounting.manage', 'name' => 'Manage accounting'],
            ['code' => 'reports.view', 'name' => 'View reports'],
            ['code' => 'settings.manage', 'name' => 'Manage settings (web)'],
            ['code' => 'suppliers.manage', 'name' => 'Manage suppliers'],
            ['code' => 'members.manage', 'name' => 'Manage members'],
            ['code' => 'tables.view', 'name' => 'View tables'],
            ['code' => 'tables.manage', 'name' => 'Manage tables (floor master)'],
            ['code' => 'qr_orders.view', 'name' => 'View QR orders'],
            ['code' => 'orders.recovery.read', 'name' => 'View order item recovery timeline'],
            ['code' => 'orders.recovery.request', 'name' => 'Report order item recovery issues'],
            ['code' => 'orders.recovery.approve', 'name' => 'Approve order item recovery resolutions'],
            ['code' => 'orders.refund.execute', 'name' => 'Execute manager-approved cash refunds'],
            ['code' => 'finance.reconcile', 'name' => 'Run payment reconciliation'],
            ['code' => 'finance.shift_close', 'name' => 'Run shift close financial posting'],
            ['code' => 'employee.portal', 'name' => 'Employee self service portal'],
        ];

        foreach ($definitions as $row) {
            Permission::query()->firstOrCreate(
                ['code' => $row['code']],
                ['name' => $row['name'], 'description' => null],
            );
        }
    }
}

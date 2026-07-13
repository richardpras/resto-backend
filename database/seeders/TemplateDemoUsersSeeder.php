<?php

namespace Database\Seeders;

use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds the four template demo users (web/src + template) and assigns them to
 * roles created by {@see DefaultRolesPermissionsSeeder}.
 */
class TemplateDemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $roleIds = Role::query()
            ->whereIn('name', ['Owner', 'Manager', 'Cashier', 'Kitchen'])
            ->pluck('id', 'name');

        /** Same emails/passwords/names as template demo tile helpers; 4-digit screen PINs per role. */
        $users = [
            ['name' => 'John Doe', 'email' => 'owner@resto.com', 'password' => 'owner', 'role' => 'Owner', 'pin' => '1234'],
            ['name' => 'Sarah Lee', 'email' => 'manager@resto.com', 'password' => 'manager', 'role' => 'Manager', 'pin' => '2345'],
            ['name' => 'Mike Tan', 'email' => 'cashier@resto.com', 'password' => 'cashier', 'role' => 'Cashier', 'pin' => '3456'],
            ['name' => 'Anna Kitchen', 'email' => 'kitchen@resto.com', 'password' => 'kitchen', 'role' => 'Kitchen', 'pin' => '4567'],
        ];

        foreach ($users as $row) {
            $roleId = $roleIds[$row['role']] ?? null;
            if ($roleId === null) {
                $this->command?->warn("Role [{$row['role']}] not found — run DefaultRolesPermissionsSeeder first.");

                continue;
            }

            // Password and PIN are hashed via User model casts (`password`, `pin_hash` => `hashed`).
            $user = User::query()->updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => $row['password'],
                    'pin_hash' => $row['pin'],
                ],
            );
            $user->roles()->sync([(int) $roleId]);
        }
    }
}

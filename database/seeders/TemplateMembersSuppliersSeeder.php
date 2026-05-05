<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Mirrors seed data from web/src/stores/memberStore.ts and web/src/stores/supplierStore.ts.
 */
class TemplateMembersSuppliersSeeder extends Seeder
{
    public function run(): void
    {
        $ts = '2026-05-05 12:00:00';

        $members = [
            [
                'name' => 'Budi Santoso',
                'phone' => '081234560001',
                'email' => 'budi@email.com',
                'birthday' => '1990-05-12',
                'notes' => null,
                'points' => 1250,
                'status' => 'active',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
            [
                'name' => 'Siti Aminah',
                'phone' => '081234560002',
                'email' => 'siti@email.com',
                'birthday' => null,
                'notes' => null,
                'points' => 480,
                'status' => 'active',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
            [
                'name' => 'Andi Wijaya',
                'phone' => '081234560003',
                'email' => null,
                'birthday' => '1985-11-03',
                'notes' => null,
                'points' => 2300,
                'status' => 'active',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
            [
                'name' => 'Dewi Lestari',
                'phone' => '081234560004',
                'email' => 'dewi@email.com',
                'birthday' => null,
                'notes' => null,
                'points' => 0,
                'status' => 'inactive',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
            [
                'name' => 'Rizky Pratama',
                'phone' => '081234560005',
                'email' => null,
                'birthday' => null,
                'notes' => null,
                'points' => 850,
                'status' => 'active',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
        ];

        foreach ($members as $row) {
            Member::query()->updateOrCreate(
                ['phone' => $row['phone']],
                [
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'birthday' => $row['birthday'],
                    'notes' => $row['notes'],
                    'points' => $row['points'],
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ],
            );
        }

        $suppliers = [
            [
                'name' => 'PT Sumber Pangan',
                'contact' => '08123456789',
                'email' => 'info@sumberpangan.id',
                'address' => 'Jl. Merdeka 12, Jakarta',
                'notes' => null,
                'status' => 'active',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
            [
                'name' => 'CV Maju Bersama',
                'contact' => '08198765432',
                'email' => 'order@majubersama.id',
                'address' => 'Jl. Sudirman 88, Bandung',
                'notes' => null,
                'status' => 'active',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
            [
                'name' => 'UD Tani Makmur',
                'contact' => '08111222333',
                'email' => 'sales@tanimakmur.id',
                'address' => 'Jl. Diponegoro 5, Surabaya',
                'notes' => null,
                'status' => 'active',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
            [
                'name' => 'Fresh Daily Co',
                'contact' => '08144556677',
                'email' => 'hello@freshdaily.id',
                'address' => 'Jl. Gatot Subroto 21, Jakarta',
                'notes' => null,
                'status' => 'inactive',
                'created_at' => $ts,
                'updated_at' => $ts,
            ],
        ];

        foreach ($suppliers as $row) {
            Supplier::query()->updateOrCreate(
                ['name' => $row['name']],
                [
                    'contact' => $row['contact'],
                    'email' => $row['email'],
                    'address' => $row['address'],
                    'notes' => $row['notes'],
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ],
            );
        }
    }
}

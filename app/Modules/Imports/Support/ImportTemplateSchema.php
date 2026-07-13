<?php

namespace App\Modules\Imports\Support;

final class ImportTemplateSchema
{
    /**
     * @return list<ImportSheetDefinition>
     */
    public static function phase1(): array
    {
        return [
            new ImportSheetDefinition(
                filename: '01_ingredients.csv',
                sheetTitle: '01_ingredients',
                columns: [
                    new ImportColumnSpec('code', 'Kode', 'Code', true, ImportColumnSpec::TYPE_TEXT, 'Kode unik bahan / Unique ingredient code'),
                    new ImportColumnSpec('name', 'Nama', 'Name', true, ImportColumnSpec::TYPE_TEXT, 'Nama bahan / Ingredient name'),
                    new ImportColumnSpec('type', 'Tipe', 'Type', false, ImportColumnSpec::TYPE_ENUM, 'ingredient, atk, atau asset', '', ['ingredient', 'atk', 'asset']),
                    new ImportColumnSpec('unit', 'Satuan', 'Unit', false, ImportColumnSpec::TYPE_TEXT, 'Contoh: kg, liter, pcs'),
                    new ImportColumnSpec('min_qty', 'Stok Minimum', 'Min Qty', false, ImportColumnSpec::TYPE_NUMBER, 'Ambang stok minimum'),
                    new ImportColumnSpec('unit_price', 'Harga Satuan', 'Unit Price', false, ImportColumnSpec::TYPE_NUMBER, 'Harga per satuan'),
                    new ImportColumnSpec('notes', 'Catatan', 'Notes', false, ImportColumnSpec::TYPE_TEXT),
                ],
                examples: [[
                    'code' => 'ING_FLOUR', 'name' => 'Tepung Terigu', 'type' => 'ingredient',
                    'unit' => 'kg', 'min_qty' => '5', 'unit_price' => '12000', 'notes' => '',
                ]],
                descriptionId: 'Bahan baku dan inventori',
                descriptionEn: 'Ingredients and inventory items',
            ),
            new ImportSheetDefinition(
                filename: '02_opening_stock.csv',
                sheetTitle: '02_opening_stock',
                columns: [
                    new ImportColumnSpec('ingredient_code', 'Kode Bahan', 'Ingredient Code', true, ImportColumnSpec::TYPE_RELATION, 'Harus ada di sheet 01 atau database', '', [], 'ingredients'),
                    new ImportColumnSpec('qty', 'Jumlah', 'Qty', true, ImportColumnSpec::TYPE_NUMBER, 'Stok awal'),
                ],
                examples: [['ingredient_code' => 'ING_FLOUR', 'qty' => '25']],
                descriptionId: 'Stok awal bahan',
                descriptionEn: 'Opening stock quantities',
            ),
            new ImportSheetDefinition(
                filename: '03_menu_categories.csv',
                sheetTitle: '03_menu_categories',
                columns: [
                    new ImportColumnSpec('code', 'Kode', 'Code', true),
                    new ImportColumnSpec('name', 'Nama', 'Name', true),
                    new ImportColumnSpec('sort_order', 'Urutan', 'Sort Order', false, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('description', 'Deskripsi', 'Description', false),
                ],
                examples: [['code' => 'makanan', 'name' => 'Makanan', 'sort_order' => '10', 'description' => '']],
                descriptionId: 'Kategori menu',
                descriptionEn: 'Menu categories',
            ),
            new ImportSheetDefinition(
                filename: '04_menu_items.csv',
                sheetTitle: '04_menu_items',
                columns: [
                    new ImportColumnSpec('code', 'Kode Menu', 'Menu Code', true),
                    new ImportColumnSpec('category_code', 'Kode Kategori', 'Category Code', true, ImportColumnSpec::TYPE_RELATION, 'Harus cocok dengan sheet 03', '', [], 'menu_categories'),
                    new ImportColumnSpec('name', 'Nama', 'Name', true),
                    new ImportColumnSpec('price', 'Harga', 'Price', true, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('emoji', 'Emoji', 'Emoji', false),
                    new ImportColumnSpec('available', 'Tersedia', 'Available', false, ImportColumnSpec::TYPE_BOOL, '1 = ya, 0 = tidak', '', ['1', '0']),
                ],
                examples: [[
                    'code' => 'MENU_NASI_GORENG', 'category_code' => 'makanan', 'name' => 'Nasi Goreng',
                    'price' => '35000', 'emoji' => '🍚', 'available' => '1',
                ]],
                descriptionId: 'Item menu',
                descriptionEn: 'Menu items',
            ),
            new ImportSheetDefinition(
                filename: '05_recipes.csv',
                sheetTitle: '05_recipes',
                columns: [
                    new ImportColumnSpec('menu_code', 'Kode Menu', 'Menu Code', true, ImportColumnSpec::TYPE_RELATION, '', '', [], 'menu_items'),
                    new ImportColumnSpec('ingredient_code', 'Kode Bahan', 'Ingredient Code', true, ImportColumnSpec::TYPE_RELATION, '', '', [], 'ingredients'),
                    new ImportColumnSpec('qty', 'Jumlah', 'Qty', true, ImportColumnSpec::TYPE_NUMBER, 'Qty per porsi'),
                ],
                examples: [['menu_code' => 'MENU_NASI_GORENG', 'ingredient_code' => 'ING_FLOUR', 'qty' => '0.2']],
                descriptionId: 'Resep / BOM',
                descriptionEn: 'Recipes / BOM',
            ),
            new ImportSheetDefinition(
                filename: '06_suppliers.csv',
                sheetTitle: '06_suppliers',
                columns: [
                    new ImportColumnSpec('code', 'Kode', 'Code', true),
                    new ImportColumnSpec('name', 'Nama', 'Name', true),
                    new ImportColumnSpec('contact', 'Kontak', 'Contact', false),
                    new ImportColumnSpec('email', 'Email', 'Email', false),
                    new ImportColumnSpec('address', 'Alamat', 'Address', false),
                    new ImportColumnSpec('status', 'Status', 'Status', false, ImportColumnSpec::TYPE_ENUM, '', '', ['active', 'inactive']),
                ],
                examples: [[
                    'code' => 'SUP_ABC', 'name' => 'Supplier ABC', 'contact' => '08123456789',
                    'email' => 'abc@example.com', 'address' => 'Jakarta', 'status' => 'active',
                ]],
                descriptionId: 'Supplier',
                descriptionEn: 'Suppliers',
            ),
            new ImportSheetDefinition(
                filename: '07_tables.csv',
                sheetTitle: '07_tables',
                columns: [
                    new ImportColumnSpec('code', 'Kode', 'Code', true),
                    new ImportColumnSpec('name', 'Nama', 'Name', true),
                    new ImportColumnSpec('capacity', 'Kapasitas', 'Capacity', false, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('zone', 'Zona', 'Zone', false),
                    new ImportColumnSpec('status', 'Status', 'Status', false, ImportColumnSpec::TYPE_ENUM, '', '', ['active', 'inactive']),
                    new ImportColumnSpec('active', 'Aktif', 'Active', false, ImportColumnSpec::TYPE_BOOL, '', '', ['1', '0']),
                ],
                examples: [[
                    'code' => 'T01', 'name' => 'Meja 1', 'capacity' => '4',
                    'zone' => 'Indoor', 'status' => 'active', 'active' => '1',
                ]],
                descriptionId: 'Meja restoran',
                descriptionEn: 'Restaurant tables',
            ),
        ];
    }

    /**
     * @return list<ImportSheetDefinition>
     */
    public static function phase2(): array
    {
        return [
            new ImportSheetDefinition(
                filename: '08_chart_of_accounts.csv',
                sheetTitle: '08_chart_of_accounts',
                columns: [
                    new ImportColumnSpec('code', 'Kode Akun', 'Account Code', true),
                    new ImportColumnSpec('name', 'Nama Akun', 'Account Name', true),
                    new ImportColumnSpec('type', 'Tipe', 'Type', true, ImportColumnSpec::TYPE_ENUM, '', '', ['asset', 'liability', 'equity', 'revenue', 'expense']),
                    new ImportColumnSpec('subtype', 'Subtipe', 'Subtype', false),
                    new ImportColumnSpec('category', 'Kategori', 'Category', false),
                    new ImportColumnSpec('parent_code', 'Kode Induk', 'Parent Code', false, ImportColumnSpec::TYPE_RELATION, '', '', [], 'accounts'),
                    new ImportColumnSpec('description', 'Deskripsi', 'Description', false),
                    new ImportColumnSpec('active', 'Aktif', 'Active', false, ImportColumnSpec::TYPE_BOOL, '', '', ['1', '0']),
                ],
                examples: [[
                    'code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset',
                    'category' => 'cash_bank', 'parent_code' => '', 'description' => '', 'active' => '1',
                ]],
                descriptionId: 'Chart of accounts',
                descriptionEn: 'Chart of accounts',
            ),
            new ImportSheetDefinition(
                filename: '09_opening_balances.csv',
                sheetTitle: '09_opening_balances',
                columns: [
                    new ImportColumnSpec('account_code', 'Kode Akun', 'Account Code', true, ImportColumnSpec::TYPE_RELATION, '', '', [], 'accounts'),
                    new ImportColumnSpec('debit', 'Debit', 'Debit', true, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('credit', 'Kredit', 'Credit', true, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('memo', 'Memo', 'Memo', false),
                    new ImportColumnSpec('journal_date', 'Tanggal Jurnal', 'Journal Date', false, ImportColumnSpec::TYPE_DATE),
                ],
                examples: [
                    ['account_code' => '1100', 'debit' => '50000000', 'credit' => '0', 'memo' => 'Opening cash', 'journal_date' => ''],
                    ['account_code' => '3100', 'debit' => '0', 'credit' => '50000000', 'memo' => 'Opening equity', 'journal_date' => ''],
                ],
                descriptionId: 'Saldo awal (debit = kredit)',
                descriptionEn: 'Opening balances (debit must equal credit)',
            ),
            new ImportSheetDefinition(
                filename: '10_customers.csv',
                sheetTitle: '10_customers',
                columns: [
                    new ImportColumnSpec('code', 'Kode Pelanggan', 'Customer Code', true),
                    new ImportColumnSpec('name', 'Nama', 'Name', true),
                    new ImportColumnSpec('phone', 'Telepon', 'Phone', false),
                    new ImportColumnSpec('email', 'Email', 'Email', false),
                ],
                examples: [['code' => 'CUST_001', 'name' => 'Budi Santoso', 'phone' => '081234567890', 'email' => 'budi@example.com']],
                descriptionId: 'Pelanggan / loyalty',
                descriptionEn: 'Customers / loyalty accounts',
            ),
            new ImportSheetDefinition(
                filename: '11_members.csv',
                sheetTitle: '11_members',
                columns: [
                    new ImportColumnSpec('code', 'Kode Member', 'Member Code', true),
                    new ImportColumnSpec('full_name', 'Nama Lengkap', 'Full Name', true),
                    new ImportColumnSpec('phone', 'Telepon', 'Phone', false),
                    new ImportColumnSpec('email', 'Email', 'Email', false),
                    new ImportColumnSpec('birth_date', 'Tanggal Lahir', 'Birth Date', false, ImportColumnSpec::TYPE_DATE),
                    new ImportColumnSpec('gender', 'Jenis Kelamin', 'Gender', false, ImportColumnSpec::TYPE_ENUM, '', '', ['male', 'female', 'other']),
                    new ImportColumnSpec('status', 'Status', 'Status', false, ImportColumnSpec::TYPE_ENUM, '', '', ['active', 'inactive']),
                    new ImportColumnSpec('customer_code', 'Kode Pelanggan', 'Customer Code', false, ImportColumnSpec::TYPE_RELATION, '', '', [], 'customers'),
                    new ImportColumnSpec('notes', 'Catatan', 'Notes', false),
                ],
                examples: [[
                    'code' => 'MEM_001', 'full_name' => 'Budi Santoso', 'phone' => '081234567890',
                    'email' => 'budi@example.com', 'birth_date' => '1990-01-15', 'gender' => 'male',
                    'status' => 'active', 'customer_code' => 'CUST_001', 'notes' => '',
                ]],
                descriptionId: 'Member',
                descriptionEn: 'Members',
            ),
            new ImportSheetDefinition(
                filename: '12_outlet_payment_methods.csv',
                sheetTitle: '12_outlet_payment_methods',
                columns: [
                    new ImportColumnSpec('payment_method_code', 'Metode Pembayaran', 'Payment Method', true, ImportColumnSpec::TYPE_ENUM, '', '', ['cash', 'manual_qris', 'gateway_qris', 'gateway_ewallet', 'manual_transfer']),
                    new ImportColumnSpec('enabled', 'Aktif', 'Enabled', false, ImportColumnSpec::TYPE_BOOL, '', '', ['1', '0']),
                    new ImportColumnSpec('is_default', 'Default', 'Is Default', false, ImportColumnSpec::TYPE_BOOL, '', '', ['1', '0']),
                    new ImportColumnSpec('display_order', 'Urutan', 'Display Order', false, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('provider', 'Provider', 'Provider', false),
                    new ImportColumnSpec('chart_account_code', 'Kode Akun', 'Chart Account', false, ImportColumnSpec::TYPE_RELATION, '', '', [], 'accounts'),
                    new ImportColumnSpec('instructions', 'Instruksi', 'Instructions', false),
                ],
                examples: [
                    ['payment_method_code' => 'cash', 'enabled' => '1', 'is_default' => '1', 'display_order' => '10', 'provider' => '', 'chart_account_code' => '1100', 'instructions' => ''],
                    ['payment_method_code' => 'manual_qris', 'enabled' => '1', 'is_default' => '0', 'display_order' => '20', 'provider' => 'manual', 'chart_account_code' => '1120', 'instructions' => 'Scan QRIS outlet lalu konfirmasi.'],
                ],
                descriptionId: 'Metode pembayaran outlet',
                descriptionEn: 'Outlet payment methods',
            ),
        ];
    }

    /**
     * @return list<ImportSheetDefinition>
     */
    public static function phase3(): array
    {
        return [
            new ImportSheetDefinition(
                filename: '13_departments.csv',
                sheetTitle: '13_departments',
                columns: [
                    new ImportColumnSpec('code', 'Kode', 'Code', true),
                    new ImportColumnSpec('name', 'Nama', 'Name', true),
                    new ImportColumnSpec('description', 'Deskripsi', 'Description', false),
                    new ImportColumnSpec('active', 'Aktif', 'Active', false, ImportColumnSpec::TYPE_BOOL, '', '', ['1', '0']),
                ],
                examples: [['code' => 'OPS', 'name' => 'Operations', 'description' => '', 'active' => '1']],
                descriptionId: 'Departemen',
                descriptionEn: 'Departments',
            ),
            new ImportSheetDefinition(
                filename: '14_positions.csv',
                sheetTitle: '14_positions',
                columns: [
                    new ImportColumnSpec('code', 'Kode', 'Code', true),
                    new ImportColumnSpec('name', 'Nama', 'Name', true),
                    new ImportColumnSpec('department_code', 'Kode Departemen', 'Department Code', false, ImportColumnSpec::TYPE_RELATION, '', '', [], 'departments'),
                    new ImportColumnSpec('description', 'Deskripsi', 'Description', false),
                    new ImportColumnSpec('sort_order', 'Urutan', 'Sort Order', false, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('active', 'Aktif', 'Active', false, ImportColumnSpec::TYPE_BOOL, '', '', ['1', '0']),
                ],
                examples: [[
                    'code' => 'WAITER', 'name' => 'Waiter', 'department_code' => 'OPS',
                    'description' => '', 'sort_order' => '10', 'active' => '1',
                ]],
                descriptionId: 'Posisi/jabatan',
                descriptionEn: 'Positions',
            ),
            new ImportSheetDefinition(
                filename: '15_employees.csv',
                sheetTitle: '15_employees',
                columns: [
                    new ImportColumnSpec('employee_no', 'Nomor Karyawan', 'Employee No', true),
                    new ImportColumnSpec('full_name', 'Nama Lengkap', 'Full Name', true),
                    new ImportColumnSpec('email', 'Email', 'Email', false),
                    new ImportColumnSpec('phone', 'Telepon', 'Phone', false),
                    new ImportColumnSpec('gender', 'Jenis Kelamin', 'Gender', false, ImportColumnSpec::TYPE_ENUM, '', '', ['male', 'female', 'other']),
                    new ImportColumnSpec('birth_date', 'Tanggal Lahir', 'Birth Date', false, ImportColumnSpec::TYPE_DATE),
                    new ImportColumnSpec('hire_date', 'Tanggal Masuk', 'Hire Date', false, ImportColumnSpec::TYPE_DATE),
                    new ImportColumnSpec('status', 'Status', 'Status', false, ImportColumnSpec::TYPE_ENUM, '', '', ['active', 'inactive', 'resigned', 'terminated']),
                    new ImportColumnSpec('department_code', 'Kode Departemen', 'Department Code', false, ImportColumnSpec::TYPE_RELATION, '', '', [], 'departments'),
                    new ImportColumnSpec('position_code', 'Kode Posisi', 'Position Code', false, ImportColumnSpec::TYPE_RELATION, '', '', [], 'positions'),
                    new ImportColumnSpec('salary_type', 'Tipe Gaji', 'Salary Type', false, ImportColumnSpec::TYPE_ENUM, '', '', ['monthly', 'daily', 'hourly']),
                    new ImportColumnSpec('base_salary', 'Gaji Dasar', 'Base Salary', false, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('overtime_rate', 'Tarif Lembur', 'Overtime Rate', false, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('notes', 'Catatan', 'Notes', false),
                ],
                examples: [[
                    'employee_no' => 'EMP-001', 'full_name' => 'Andi Pratama', 'email' => 'andi@example.com',
                    'phone' => '081234567890', 'gender' => 'male', 'birth_date' => '1995-03-10',
                    'hire_date' => '2024-01-01', 'status' => 'active', 'department_code' => 'OPS',
                    'position_code' => 'WAITER', 'salary_type' => 'monthly', 'base_salary' => '4500000',
                    'overtime_rate' => '0', 'notes' => '',
                ]],
                descriptionId: 'Data karyawan',
                descriptionEn: 'Employees',
            ),
            new ImportSheetDefinition(
                filename: '16_opening_loyalty_points.csv',
                sheetTitle: '16_opening_loyalty_points',
                columns: [
                    new ImportColumnSpec('customer_code', 'Kode Pelanggan', 'Customer Code', true, ImportColumnSpec::TYPE_RELATION, 'Harus ada di Phase 2 customers', '', [], 'customers'),
                    new ImportColumnSpec('points', 'Poin', 'Points', true, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('memo', 'Memo', 'Memo', false),
                ],
                examples: [['customer_code' => 'CUST_001', 'points' => '500', 'memo' => 'Opening balance']],
                descriptionId: 'Poin loyalty awal',
                descriptionEn: 'Opening loyalty points',
            ),
        ];
    }

    /**
     * @return list<ImportSheetDefinition>
     */
    public static function phase4(): array
    {
        return [
            new ImportSheetDefinition(
                filename: '17_employee_salary_profiles.csv',
                sheetTitle: '17_employee_salary_profiles',
                columns: [
                    new ImportColumnSpec('employee_no', 'Nomor Karyawan', 'Employee No', true, ImportColumnSpec::TYPE_RELATION, 'Harus ada di Phase 3 employees', '', [], 'employees'),
                    new ImportColumnSpec('basic_salary', 'Gaji Pokok', 'Basic Salary', true, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('default_allowance', 'Tunjangan Default', 'Default Allowance', false, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('default_deduction', 'Potongan Default', 'Default Deduction', false, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('overtime_rate_type', 'Tipe Tarif Lembur', 'Overtime Rate Type', false, ImportColumnSpec::TYPE_ENUM, '', '', ['fixed_hourly', 'multiplier_hourly_salary']),
                    new ImportColumnSpec('overtime_rate_value', 'Nilai Tarif Lembur', 'Overtime Rate Value', false, ImportColumnSpec::TYPE_NUMBER),
                    new ImportColumnSpec('unpaid_leave_deduction_enabled', 'Potongan Cuti Tanpa Gaji', 'Unpaid Leave Deduction', false, ImportColumnSpec::TYPE_BOOL, '', '', ['1', '0']),
                    new ImportColumnSpec('attendance_deduction_enabled', 'Potongan Absensi', 'Attendance Deduction', false, ImportColumnSpec::TYPE_BOOL, '', '', ['1', '0']),
                    new ImportColumnSpec('attendance_deduction_per_day', 'Potongan per Hari', 'Deduction Per Day', false, ImportColumnSpec::TYPE_NUMBER, 'Wajib jika potongan absensi aktif'),
                ],
                examples: [[
                    'employee_no' => 'EMP-001', 'basic_salary' => '5000000', 'default_allowance' => '500000',
                    'default_deduction' => '100000', 'overtime_rate_type' => 'fixed_hourly',
                    'overtime_rate_value' => '25000', 'unpaid_leave_deduction_enabled' => '1',
                    'attendance_deduction_enabled' => '0', 'attendance_deduction_per_day' => '',
                ]],
                descriptionId: 'Profil gaji karyawan',
                descriptionEn: 'Employee salary profiles',
            ),
        ];
    }

    /**
     * @param  list<ImportSheetDefinition>  $sheets
     * @return array<string, array{headers: list<string>, examples: list<array<string, string>>, columnSpecs: list<ImportColumnSpec>}>
     */
    public static function toLegacySheetMap(array $sheets): array
    {
        $map = [];
        foreach ($sheets as $sheet) {
            $map[$sheet->filename] = $sheet->toLegacyDefinition();
        }

        return $map;
    }

    /**
     * @return list<ImportColumnSpec>|null
     */
    public static function columnSpecsForFilename(string $phase, string $filename): ?array
    {
        $sheet = self::findSheet($phase, $filename);

        return $sheet?->columns;
    }

    public static function findSheet(string $phase, string $filename): ?ImportSheetDefinition
    {
        foreach (match ($phase) {
            'phase1' => self::phase1(),
            'phase2' => self::phase2(),
            'phase3' => self::phase3(),
            'phase4' => self::phase4(),
            default => [],
        } as $sheet) {
            if (strcasecmp($sheet->filename, $filename) === 0) {
                return $sheet;
            }
        }

        return null;
    }
}

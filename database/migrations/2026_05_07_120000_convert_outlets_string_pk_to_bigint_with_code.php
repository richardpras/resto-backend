<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts outlets.id from varchar PK to bigint auto-increment; legacy PK string becomes outlets.code.
 * Remaps menu_item_outlets, outlet_receipt_settings, setting_printers outlet_id FKs (string → bigint).
 *
 * Resolved bigint per legacy key: CAST if digits-only OR config/outlet_bridge.by_key
 * OR baked-in defaults o-main=>1, o-branch=>2 (template parity with transactional outlet_id values).
 *
 * Irreversible. Requires MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outlets')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            throw new \RuntimeException('2026_05_07_convert_outlets PK migration supports MySQL only.');
        }

        if ($this->outletsPkIsBigInt()) {
            return;
        }

        Schema::table('outlets', function (Blueprint $table): void {
            $table->string('code', 64)->nullable()->after('name');
        });

        foreach (DB::table('outlets')->cursor() as $row) {
            DB::table('outlets')->where('id', $row->id)->update(['code' => $row->id]);
        }

        Schema::table('outlets', function (Blueprint $table): void {
            $table->unsignedBigInteger('_id_new')->nullable()->after('id');
        });

        $defaults = ['o-main' => 1, 'o-branch' => 2];
        $bridge = array_merge($defaults, config('outlet_bridge.by_key', []));

        foreach (DB::table('outlets')->orderBy('id')->cursor() as $row) {
            $sidStr = (string) $row->id;

            if (preg_match('/^\d+$/', $sidStr) === 1) {
                $nid = (int) $sidStr;
            } elseif (array_key_exists($sidStr, $bridge)) {
                $nid = (int) $bridge[$sidStr];
            } else {
                throw new \RuntimeException(
                    "Outlet PK migration: outlet id \"{$sidStr}\" needs a mapping. "
                    .'Use numeric outlets.id or add config/outlet_bridge.by_key.'
                );
            }

            if ($nid < 1) {
                throw new \RuntimeException("Outlet PK migration: resolved id must be >= 1 for \"{$sidStr}\".");
            }

            DB::table('outlets')->where('id', $row->id)->update(['_id_new' => $nid]);
        }

        if (DB::table('outlets')
            ->selectRaw('_id_new, COUNT(*) as c')
            ->groupBy('_id_new')
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new \RuntimeException('Outlet PK migration: duplicate `_id_new` assignment — check mappings.');
        }

        $this->rewriteChildOutletColumn('menu_item_outlets');
        $this->rewriteChildOutletColumn('outlet_receipt_settings');
        $this->rewriteChildOutletColumn('setting_printers');

        DB::statement('ALTER TABLE outlets DROP PRIMARY KEY');
        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn('id');
        });
        DB::statement(
            <<<'SQL'
ALTER TABLE outlets
CHANGE `_id_new` `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
ADD PRIMARY KEY (`id`)
SQL
        );

        DB::statement('ALTER TABLE outlets MODIFY `code` VARCHAR(64) NOT NULL');
        Schema::table('outlets', function (Blueprint $table): void {
            $table->unique('code');
        });

        $maxId = (int) DB::table('outlets')->max('id');
        DB::statement('ALTER TABLE outlets AUTO_INCREMENT = '.max(2, $maxId + 1));

        Schema::table('menu_item_outlets', function (Blueprint $table): void {
            $table->unique(['menu_item_id', 'outlet_id']);
            $table->foreign('menu_item_id')->references('id')->on('menu_items')->cascadeOnDelete();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });

        Schema::table('outlet_receipt_settings', function (Blueprint $table): void {
            $table->unique('outlet_id');
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });

        Schema::table('setting_printers', function (Blueprint $table): void {
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('2026_05_07_convert_outlets PK migration is irreversible.');
    }

    private function outletsPkIsBigInt(): bool
    {
        /** @var object|null $row */
        $row = DB::selectOne('SHOW COLUMNS FROM outlets WHERE Field = ?', ['id']);

        return $row !== null && str_contains(strtolower((string) $row->Type), 'bigint');
    }

    private function dropMysqlAllForeignKeysForTable(string $table): void
    {
        $database = Schema::getConnection()->getDatabaseName();

        $constraints = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS '
            .'WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table]
        );

        foreach ($constraints as $c) {
            $name = $c->CONSTRAINT_NAME ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
        }
    }

    private function dropMysqlForeignKeysToOutlets(string $table): void
    {
        $database = Schema::getConnection()->getDatabaseName();

        $constraints = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS '
            .'WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME = ?',
            [$database, $table, 'outlets']
        );

        foreach ($constraints as $c) {
            $name = $c->CONSTRAINT_NAME ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }
            DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
        }
    }

    private function rewriteChildOutletColumn(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if ($table === 'menu_item_outlets') {
            $this->dropMysqlAllForeignKeysForTable($table);
        } else {
            $this->dropMysqlForeignKeysToOutlets($table);
        }

        if ($table === 'menu_item_outlets') {
            Schema::table($table, function (Blueprint $tableDef): void {
                $tableDef->dropUnique(['menu_item_id', 'outlet_id']);
            });
        }

        Schema::table($table, function (Blueprint $tableDef): void {
            $tableDef->unsignedBigInteger('outlet_id_new')->nullable();
        });

        DB::statement("UPDATE `{$table}` AS c INNER JOIN outlets AS o ON c.outlet_id = o.id SET c.outlet_id_new = o._id_new");

        if (DB::table($table)->whereNull('outlet_id_new')->exists()) {
            throw new \RuntimeException("Outlet PK migration: `{$table}` has rows whose outlet could not be resolved.");
        }

        Schema::table($table, function (Blueprint $tableDef): void {
            $tableDef->dropColumn('outlet_id');
        });

        DB::statement("ALTER TABLE `{$table}` CHANGE COLUMN `outlet_id_new` `outlet_id` BIGINT UNSIGNED NOT NULL");
    }
};

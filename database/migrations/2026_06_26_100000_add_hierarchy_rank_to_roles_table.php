<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->unsignedTinyInteger('hierarchy_rank')->default(10)->after('staff_assignable');
        });

        $this->backfillHierarchyRanks();
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('hierarchy_rank');
        });
    }

    private function backfillHierarchyRanks(): void
    {
        $roles = DB::table('roles')->select('id', 'name', 'staff_assignable')->get();

        foreach ($roles as $role) {
            $name = (string) $role->name;
            $rank = 10;

            if (stripos($name, 'admin') !== false) {
                $rank = 100;
            } elseif (stripos($name, 'owner') !== false) {
                $rank = 90;
            } elseif (stripos($name, 'auditor') !== false) {
                $rank = 85;
            } elseif (stripos($name, 'manager') !== false) {
                $rank = 50;
            } elseif (! (bool) $role->staff_assignable) {
                $rank = 90;
            }

            DB::table('roles')->where('id', $role->id)->update(['hierarchy_rank' => $rank]);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            if (! Schema::hasColumn('members', 'outlet_id')) {
                $table->unsignedBigInteger('outlet_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('members', 'member_no')) {
                $table->string('member_no', 32)->nullable()->after('outlet_id');
            }
            if (! Schema::hasColumn('members', 'full_name')) {
                $table->string('full_name')->nullable()->after('member_no');
            }
            if (! Schema::hasColumn('members', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('email');
            }
            if (! Schema::hasColumn('members', 'gender')) {
                $table->string('gender', 16)->nullable()->after('birth_date');
            }
            if (! Schema::hasColumn('members', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('notes');
            }
        });

        if (Schema::hasColumn('members', 'name')) {
            DB::table('members')->orderBy('id')->lazyById()->each(function (object $row): void {
                $updates = [];
                if (Schema::hasColumn('members', 'full_name') && empty($row->full_name) && ! empty($row->name)) {
                    $updates['full_name'] = $row->name;
                }
                if (Schema::hasColumn('members', 'birth_date') && empty($row->birth_date) && ! empty($row->birthday)) {
                    $updates['birth_date'] = $row->birthday;
                }
                if (Schema::hasColumn('members', 'is_active') && $row->is_active === null) {
                    $updates['is_active'] = ($row->status ?? 'active') === 'active';
                }
                if (Schema::hasColumn('members', 'member_no') && empty($row->member_no)) {
                    $updates['member_no'] = 'LEG-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT);
                }
                if ($updates !== []) {
                    DB::table('members')->where('id', $row->id)->update($updates);
                }
            });
        }

        Schema::table('members', function (Blueprint $table): void {
            if (Schema::hasColumn('members', 'outlet_id') && Schema::hasColumn('members', 'is_active')) {
                $table->index(['outlet_id', 'is_active'], 'members_outlet_active_idx');
            }
            if (Schema::hasColumn('members', 'outlet_id') && Schema::hasColumn('members', 'member_no')) {
                $table->unique(['outlet_id', 'member_no'], 'members_outlet_member_no_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            if (Schema::hasColumn('members', 'member_no')) {
                $table->dropUnique('members_outlet_member_no_unique');
            }
            foreach (['gender', 'birth_date', 'full_name', 'member_no', 'outlet_id', 'is_active'] as $column) {
                if (Schema::hasColumn('members', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

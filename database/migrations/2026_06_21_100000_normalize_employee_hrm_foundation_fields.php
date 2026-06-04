<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $outlets = DB::table('outlets')->get(['id', 'name', 'code']);

        $employees = DB::table('employees')->get([
            'id',
            'outlet_id',
            'outlet',
            'position_id',
            'position',
        ]);

        foreach ($employees as $row) {
            $updates = [];

            if ($row->outlet_id !== null) {
                $outlet = $outlets->firstWhere('id', (int) $row->outlet_id);
                if ($outlet !== null && $row->outlet !== $outlet->name) {
                    $updates['outlet'] = $outlet->name;
                }
            } elseif ($row->outlet !== null && trim((string) $row->outlet) !== '') {
                $label = trim((string) $row->outlet);
                $match = $outlets->first(
                    fn ($o) => $o->name === $label || ($o->code !== null && $o->code === $label),
                );
                if ($match !== null) {
                    $updates['outlet_id'] = (int) $match->id;
                }
            }

            if ($row->position_id !== null) {
                $name = DB::table('positions')->where('id', $row->position_id)->value('name');
                if ($name !== null && $row->position !== $name) {
                    $updates['position'] = $name;
                }
            }

            if ($updates !== []) {
                DB::table('employees')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive data normalization; no rollback of synced labels.
    }
};

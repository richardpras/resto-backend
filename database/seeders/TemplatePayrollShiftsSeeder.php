<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Shift;
use Database\Seeders\Concerns\LoadsTemplatePayrollData;
use Illuminate\Database\Seeder;

class TemplatePayrollShiftsSeeder extends Seeder
{
    use LoadsTemplatePayrollData;

    public function run(): void
    {
        $data = $this->templatePayrollData();

        foreach ($data['shifts'] as $row) {
            $normalizedStart = $this->normalizeTime($row['startTime']);
            $normalizedEnd = $this->normalizeTime($row['endTime']);

            $existing = Shift::query()
                ->where('start_time', $normalizedStart)
                ->where('end_time', $normalizedEnd)
                ->first();

            if ($existing !== null) {
                if (! empty($row['notes']) && empty($existing->notes)) {
                    $existing->update(['notes' => $row['notes']]);
                }
                continue;
            }

            $code = sprintf(
                'SHIFT-%s-%s',
                str_replace(':', '', substr($normalizedStart, 0, 5)),
                str_replace(':', '', substr($normalizedEnd, 0, 5))
            );

            Shift::query()->updateOrCreate(
                ['code' => $code],
                [
                    'tenant_id' => null,
                    'name' => 'Shift '.$normalizedStart.'-'.$normalizedEnd,
                    'start_time' => $normalizedStart,
                    'end_time' => $normalizedEnd,
                    'late_tolerance_minutes' => 0,
                    'overtime_after_minutes' => 0,
                    'active' => true,
                    'notes' => $row['notes'] ?? null,
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
        }
    }

    private function normalizeTime(string $value): string
    {
        return strlen($value) === 5 ? $value.':00' : $value;
    }
}

<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeTaxProfile;
use App\Models\Modules\HR\Domain\Pph21Config;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EmployeeTaxProfileService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, EmployeeTaxProfile>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = EmployeeTaxProfile::query()->with('employee')->orderByDesc('id');

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['outletId'])) {
            $query->whereHas('employee', fn ($q) => $q->where('outlet_id', (int) $filters['outletId']));
        }

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $profileId): EmployeeTaxProfile
    {
        $profile = EmployeeTaxProfile::query()->with('employee')->find($profileId);

        abort_if($profile === null, Response::HTTP_NOT_FOUND, 'Employee tax profile not found.');

        $profile->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $profile->employee);

        return $profile;
    }

    public function upsertForEmployee(?User $user, int $employeeId, array $payload): EmployeeTaxProfile
    {
        $employee = $this->employeeMaster->findAccessible($user, $employeeId);

        $profile = EmployeeTaxProfile::query()->firstOrCreate(
            ['employee_id' => $employee->id],
            [
                'ptkp_status' => 'TK0',
                'pph21_enabled' => false,
            ],
        );

        return $this->applyUpdate($profile, $payload);
    }

    public function update(?User $user, int $profileId, array $payload): EmployeeTaxProfile
    {
        $profile = $this->findAccessible($user, $profileId);

        return $this->applyUpdate($profile, $payload);
    }

    private function applyUpdate(EmployeeTaxProfile $profile, array $payload): EmployeeTaxProfile
    {
        if (array_key_exists('ptkpStatus', $payload)) {
            $status = (string) $payload['ptkpStatus'];
            if (! in_array($status, Pph21Config::PTKP_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'ptkpStatus' => ['Invalid PTKP status.'],
                ]);
            }
        }

        $map = [
            'npwpNumber' => 'npwp_number',
            'ptkpStatus' => 'ptkp_status',
            'pph21Enabled' => 'pph21_enabled',
        ];

        $data = [];
        foreach ($map as $key => $column) {
            if (array_key_exists($key, $payload)) {
                $data[$column] = $payload[$key];
            }
        }

        if ($data !== []) {
            $profile->update($data);
        }

        return $profile->refresh()->load('employee');
    }
}

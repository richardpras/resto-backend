<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\BpjsProfile;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class BpjsProfileService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return Collection<int, BpjsProfile>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = BpjsProfile::query()->with('employee')->orderByDesc('id');

        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');

        if (! empty($filters['outletId'])) {
            $query->whereHas('employee', fn ($q) => $q->where('outlet_id', (int) $filters['outletId']));
        }

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        return $query->get();
    }

    public function findAccessible(?User $user, int $profileId): BpjsProfile
    {
        $profile = BpjsProfile::query()->with('employee')->find($profileId);

        abort_if($profile === null, Response::HTTP_NOT_FOUND, 'BPJS profile not found.');

        $profile->loadMissing('employee');
        $this->employeeMaster->assertEmployeeOutletAllowed($user, $profile->employee);

        return $profile;
    }

    public function upsertForEmployee(?User $user, int $employeeId, array $payload): BpjsProfile
    {
        $employee = $this->employeeMaster->findAccessible($user, $employeeId);

        $profile = BpjsProfile::query()->firstOrCreate(
            ['employee_id' => $employee->id],
            [
                'bpjs_kesehatan_enabled' => false,
                'bpjs_tk_enabled' => false,
            ],
        );

        return $this->applyUpdate($profile, $payload);
    }

    public function update(?User $user, int $profileId, array $payload): BpjsProfile
    {
        $profile = $this->findAccessible($user, $profileId);

        return $this->applyUpdate($profile, $payload);
    }

    private function applyUpdate(BpjsProfile $profile, array $payload): BpjsProfile
    {
        $map = [
            'bpjsKesehatanNo' => 'bpjs_kesehatan_no',
            'bpjsTkNo' => 'bpjs_tk_no',
            'bpjsKesehatanEnabled' => 'bpjs_kesehatan_enabled',
            'bpjsTkEnabled' => 'bpjs_tk_enabled',
            'bpjsSalaryBase' => 'bpjs_salary_base',
            'kesehatanEmployeeRateOverride' => 'kesehatan_employee_rate_override',
            'kesehatanCompanyRateOverride' => 'kesehatan_company_rate_override',
            'jhtEmployeeRateOverride' => 'jht_employee_rate_override',
            'jhtCompanyRateOverride' => 'jht_company_rate_override',
            'jpEmployeeRateOverride' => 'jp_employee_rate_override',
            'jpCompanyRateOverride' => 'jp_company_rate_override',
            'jkkCompanyRateOverride' => 'jkk_company_rate_override',
            'jkmCompanyRateOverride' => 'jkm_company_rate_override',
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

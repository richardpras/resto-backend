<?php

namespace Tests\Concerns;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;

trait OrganizationStructureTestFixtures
{
    protected function actingAsOrganizationAdmin(): User
    {
        $user = $this->actingAsUserManagementApiAdministrator();

        return $user;
    }

    protected function createOrganizationOutlet(string $suffix = ''): Outlet
    {
        $token = $suffix !== '' ? $suffix : uniqid('', true);

        return Outlet::query()->create([
            'name' => 'Org Test Outlet '.$token,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'org-'.$token,
        ]);
    }
}

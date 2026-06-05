<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Concerns\EssPortalFixture;
use Tests\TestCase;

class EmployeePortalProfileTest extends TestCase
{
    use EssPortalFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->setupEssPassport();
        $this->enableEssPortal();
    }

    public function test_own_profile_accessible(): void
    {
        [$employee, $user] = $this->seedEmployeePortalUser();
        Passport::actingAs($user, [], 'employee_api');

        $this->getJson('/api/v1/ess/profile')
            ->assertOk()
            ->assertJsonPath('data.employee.id', (int) $employee->id)
            ->assertJsonPath('data.employee.fullName', 'ESS Worker')
            ->assertJsonPath('data.employmentStatus.status', 'active');
    }

    public function test_profile_scoped_to_authenticated_employee_only(): void
    {
        [$employeeA, $userA] = $this->seedEmployeePortalUser('employee.a@test.local');
        [, $userB] = $this->seedEmployeePortalUser('employee.b@test.local');

        Passport::actingAs($userA, [], 'employee_api');

        $profileA = $this->getJson('/api/v1/ess/profile')->assertOk();
        $this->assertSame((int) $employeeA->id, (int) $profileA->json('data.employee.id'));
        $this->assertSame('employee.a@test.local', $profileA->json('data.employee.email'));

        Passport::actingAs($userB, [], 'employee_api');

        $this->getJson('/api/v1/ess/profile')
            ->assertOk()
            ->assertJsonPath('data.employee.email', 'employee.b@test.local');
    }
}

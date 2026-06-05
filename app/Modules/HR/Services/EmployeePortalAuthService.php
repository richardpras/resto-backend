<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\EmployeeUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EmployeePortalAuthService
{
    public function __construct(
        private readonly EssFeatureService $essFeature,
    ) {}

    /**
     * @return array{accessToken: string, tokenType: string, expiresIn: ?int, expiresAt: ?string, employeeUser: EmployeeUser}
     */
    public function login(string $email, string $password): array
    {
        $this->essFeature->assertEnabled();

        $user = EmployeeUser::query()
            ->with('employee')
            ->where('email', $email)
            ->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        if (! $user->is_active) {
            abort(Response::HTTP_FORBIDDEN, 'Employee portal account is inactive.');
        }

        $user->update(['last_login_at' => now()]);

        $tokenResult = $user->createToken('ess-portal');
        $tokenModel = $tokenResult->getToken();

        return [
            'accessToken' => $tokenResult->accessToken,
            'tokenType' => $tokenResult->tokenType ?? 'Bearer',
            'expiresIn' => $tokenResult->expiresIn ?? null,
            'expiresAt' => $tokenModel?->expires_at?->toIso8601String(),
            'employeeUser' => $user->refresh()->load('employee'),
        ];
    }

    public function logout(?EmployeeUser $user): void
    {
        if ($user === null) {
            return;
        }

        $token = $user->token();
        if ($token !== null) {
            $token->revoke();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function me(EmployeeUser $user): array
    {
        $user->loadMissing('employee.outletRelation');

        return [
            'id' => (int) $user->id,
            'email' => $user->email,
            'employeeId' => (int) $user->employee_id,
            'isActive' => (bool) $user->is_active,
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
            'permissionCodes' => ['employee.portal'],
            'employee' => $user->employee ? [
                'id' => (int) $user->employee->id,
                'employeeNo' => $user->employee->employee_no,
                'fullName' => $user->employee->full_name,
                'outletId' => (int) $user->employee->outlet_id,
            ] : null,
        ];
    }
}

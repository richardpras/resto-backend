<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\EmployeeUser;
use App\Modules\HR\Services\EmployeePortalAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EssAuthController extends Controller
{
    public function __construct(
        private readonly EmployeePortalAuthService $auth,
    ) {}

    public function login(): JsonResponse
    {
        $validated = request()->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $result = $this->auth->login($validated['email'], $validated['password']);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => $e->errors(),
            ], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'accessToken' => $result['accessToken'],
                'tokenType' => $result['tokenType'],
                'expiresIn' => $result['expiresIn'],
                'expiresAt' => $result['expiresAt'],
                'employeeUser' => [
                    'id' => (int) $result['employeeUser']->id,
                    'email' => $result['employeeUser']->email,
                    'employeeId' => (int) $result['employeeUser']->employee_id,
                ],
            ],
        ]);
    }

    public function logout(): JsonResponse
    {
        $user = request()->user('employee_api');
        $this->auth->logout($user instanceof EmployeeUser ? $user : null);

        return response()->json(['message' => 'Logout successful.']);
    }

    public function me(): JsonResponse
    {
        $user = $this->resolveEmployeeUser();

        return response()->json(['data' => $this->auth->me($user)]);
    }

    private function resolveEmployeeUser(): EmployeeUser
    {
        $user = request()->user('employee_api');
        abort_if(! $user instanceof EmployeeUser, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        return $user;
    }
}

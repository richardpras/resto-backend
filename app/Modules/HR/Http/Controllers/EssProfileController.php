<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\EmployeeUser;
use App\Modules\HR\Services\EmployeeProfileService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EssProfileController extends Controller
{
    public function __construct(
        private readonly EmployeeProfileService $profiles,
    ) {}

    public function show(): JsonResponse
    {
        $user = request()->user('employee_api');
        abort_if(! $user instanceof EmployeeUser, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        return response()->json([
            'data' => $this->profiles->profileForEmployeeUser($user),
        ]);
    }
}

<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\EmployeeUser;
use App\Modules\HR\Services\EmployeeDashboardService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class EssDashboardController extends Controller
{
    public function __construct(
        private readonly EmployeeDashboardService $dashboard,
    ) {}

    public function show(): JsonResponse
    {
        $user = request()->user('employee_api');
        abort_if(! $user instanceof EmployeeUser, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        return response()->json([
            'data' => $this->dashboard->dashboardForEmployeeUser($user),
        ]);
    }
}

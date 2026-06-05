<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\PayrollPostingResource;
use App\Modules\HR\Services\PayrollPostingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PayrollPostingController extends Controller
{
    public function __construct(
        private readonly PayrollPostingService $posting,
    ) {}

    public function preview(int $run): JsonResponse
    {
        $data = $this->posting->preview($this->resolveUser(), $run);

        return response()->json(['data' => $data]);
    }

    public function post(int $run): JsonResponse
    {
        $row = $this->posting->post($this->resolveUser(), $run);

        return response()->json([
            'message' => 'Payroll posted to accounting.',
            'data' => new PayrollPostingResource($row),
        ], Response::HTTP_CREATED);
    }

    public function reverse(int $run): JsonResponse
    {
        $validated = request()->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->posting->reverse($this->resolveUser(), $run, $validated['notes'] ?? null);

        return response()->json([
            'message' => 'Payroll posting reversed.',
            'data' => new PayrollPostingResource($row),
        ]);
    }

    public function status(int $run): JsonResponse
    {
        $row = $this->posting->status($this->resolveUser(), $run);

        return response()->json([
            'data' => $row !== null ? new PayrollPostingResource($row) : null,
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

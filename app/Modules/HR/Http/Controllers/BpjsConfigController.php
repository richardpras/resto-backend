<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\HR\Domain\BpjsConfig;
use App\Modules\HR\Http\Resources\BpjsConfigResource;
use App\Modules\HR\Services\BpjsConfigService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class BpjsConfigController extends Controller
{
    public function __construct(
        private readonly BpjsConfigService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => BpjsConfigResource::collection($this->service->list()),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'effectiveDate' => ['required', 'date'],
            'kesehatanEmployeeRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'kesehatanCompanyRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jhtEmployeeRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jhtCompanyRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jpEmployeeRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jpCompanyRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jkkCompanyRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'jkmCompanyRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $row = $this->service->create($validated);

        return response()->json([
            'message' => 'BPJS configuration created.',
            'data' => new BpjsConfigResource($row),
        ], Response::HTTP_CREATED);
    }
}

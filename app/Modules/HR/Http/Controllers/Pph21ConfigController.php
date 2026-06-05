<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\Pph21ConfigResource;
use App\Modules\HR\Services\Pph21ConfigurationService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class Pph21ConfigController extends Controller
{
    public function __construct(
        private readonly Pph21ConfigurationService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Pph21ConfigResource::collection($this->service->list()),
        ]);
    }

    public function store(): JsonResponse
    {
        $validated = request()->validate([
            'effectiveDate' => ['required', 'date'],
            'ptkpTk0' => ['nullable', 'numeric', 'min:0'],
            'ptkpTk1' => ['nullable', 'numeric', 'min:0'],
            'ptkpTk2' => ['nullable', 'numeric', 'min:0'],
            'ptkpTk3' => ['nullable', 'numeric', 'min:0'],
            'ptkpK0' => ['nullable', 'numeric', 'min:0'],
            'ptkpK1' => ['nullable', 'numeric', 'min:0'],
            'ptkpK2' => ['nullable', 'numeric', 'min:0'],
            'ptkpK3' => ['nullable', 'numeric', 'min:0'],
            'isActive' => ['nullable', 'boolean'],
            'brackets' => ['nullable', 'array', 'min:1'],
            'brackets.*.incomeFrom' => ['required_with:brackets', 'numeric', 'min:0'],
            'brackets.*.incomeTo' => ['nullable', 'numeric', 'min:0'],
            'brackets.*.taxRate' => ['required_with:brackets', 'numeric', 'min:0', 'max:100'],
        ]);

        $row = $this->service->create($validated);

        return response()->json([
            'message' => 'PPh21 configuration created.',
            'data' => new Pph21ConfigResource($row),
        ], Response::HTTP_CREATED);
    }

    public function update(int $pph21Config): JsonResponse
    {
        $validated = request()->validate([
            'effectiveDate' => ['sometimes', 'date'],
            'ptkpTk0' => ['sometimes', 'numeric', 'min:0'],
            'ptkpTk1' => ['sometimes', 'numeric', 'min:0'],
            'ptkpTk2' => ['sometimes', 'numeric', 'min:0'],
            'ptkpTk3' => ['sometimes', 'numeric', 'min:0'],
            'ptkpK0' => ['sometimes', 'numeric', 'min:0'],
            'ptkpK1' => ['sometimes', 'numeric', 'min:0'],
            'ptkpK2' => ['sometimes', 'numeric', 'min:0'],
            'ptkpK3' => ['sometimes', 'numeric', 'min:0'],
            'isActive' => ['sometimes', 'boolean'],
            'brackets' => ['sometimes', 'array', 'min:1'],
            'brackets.*.incomeFrom' => ['required_with:brackets', 'numeric', 'min:0'],
            'brackets.*.incomeTo' => ['nullable', 'numeric', 'min:0'],
            'brackets.*.taxRate' => ['required_with:brackets', 'numeric', 'min:0', 'max:100'],
        ]);

        $row = $this->service->update($pph21Config, $validated);

        return response()->json([
            'message' => 'PPh21 configuration updated.',
            'data' => new Pph21ConfigResource($row),
        ]);
    }
}

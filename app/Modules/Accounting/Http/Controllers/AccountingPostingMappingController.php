<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AccountingPostingMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingPostingMappingController extends Controller
{
    public function __construct(
        private readonly AccountingPostingMappingService $postingMappingService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['required', 'string', Rule::in(AccountingPostingMappingService::SUPPORTED_MODULES)],
            'tenantId' => ['nullable', 'integer'],
            'outletId' => ['nullable', 'integer'],
        ]);

        $tenantId = isset($validated['tenantId']) ? (int) $validated['tenantId'] : null;
        $outletId = isset($validated['outletId']) ? (int) $validated['outletId'] : null;

        return response()->json([
            'data' => $this->postingMappingService->getMappings($tenantId, $outletId, (string) $validated['module']),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['required', 'string', Rule::in(AccountingPostingMappingService::SUPPORTED_MODULES)],
            'tenantId' => ['nullable', 'integer'],
            'outletId' => ['nullable', 'integer'],
        ]);

        $tenantId = isset($validated['tenantId']) ? (int) $validated['tenantId'] : null;
        $outletId = isset($validated['outletId']) ? (int) $validated['outletId'] : null;

        return response()->json([
            'data' => $this->postingMappingService->getStatus($tenantId, $outletId, (string) $validated['module']),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module' => ['required', 'string', Rule::in(AccountingPostingMappingService::SUPPORTED_MODULES)],
            'tenantId' => ['nullable', 'integer'],
            'outletId' => ['nullable', 'integer'],
            'mappings' => ['required', 'array'],
            'mappings.*.ruleKey' => ['required', 'string', 'max:128'],
            'mappings.*.chartAccountId' => ['required', 'integer', 'min:1'],
            'bankOverrides' => ['sometimes', 'array'],
            'bankOverrides.*.bankAccountId' => ['required_with:bankOverrides', 'string', 'max:64'],
            'bankOverrides.*.chartAccountId' => ['nullable', 'integer', 'min:1'],
            'paymentOverrides' => ['sometimes', 'array'],
            'paymentOverrides.*.paymentMethodCode' => ['required_with:paymentOverrides', 'string', 'max:64'],
            'paymentOverrides.*.chartAccountId' => ['nullable', 'integer', 'min:1'],
        ]);

        $tenantId = isset($validated['tenantId']) ? (int) $validated['tenantId'] : null;
        $outletId = isset($validated['outletId']) ? (int) $validated['outletId'] : null;

        $data = $this->postingMappingService->updateMappings(
            $request->user('api'),
            $tenantId,
            $outletId,
            (string) $validated['module'],
            $validated['mappings'],
            $validated['bankOverrides'] ?? [],
            $validated['paymentOverrides'] ?? [],
        );

        return response()->json([
            'message' => 'Posting mappings updated.',
            'data' => $data,
        ]);
    }
}

<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TaxSettingsCrudController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->domain->listTaxes()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'value' => ['required', 'numeric'],
            'applyDineIn' => ['required', 'boolean'],
            'applyTakeaway' => ['required', 'boolean'],
            'inclusive' => ['required', 'boolean'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
        ]);

        return response()->json([
            'message' => 'Tax created successfully.',
            'data' => $this->domain->createTax($v),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $taxId): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'value' => ['required', 'numeric'],
            'applyDineIn' => ['required', 'boolean'],
            'applyTakeaway' => ['required', 'boolean'],
            'inclusive' => ['required', 'boolean'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'effectiveFrom' => ['nullable', 'date'],
            'effectiveTo' => ['nullable', 'date', 'after_or_equal:effectiveFrom'],
        ]);

        return response()->json([
            'message' => 'Tax updated successfully.',
            'data' => $this->domain->updateTax($taxId, $v),
        ]);
    }

    public function destroy(string $taxId): JsonResponse
    {
        $this->domain->deleteTax($taxId);

        return response()->json(['message' => 'Tax deleted successfully.']);
    }
}

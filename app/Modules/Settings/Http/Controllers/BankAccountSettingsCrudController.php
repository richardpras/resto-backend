<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BankAccountSettingsCrudController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->domain->listBankAccounts()]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'id' => ['required', 'string', 'max:64'],
            'bankName' => ['required', 'string', 'max:255'],
            'accountName' => ['required', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:64'],
            'isDefault' => ['required', 'boolean'],
            'chartAccountId' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        return response()->json([
            'message' => 'Bank account created successfully.',
            'data' => $this->domain->createBankAccount($v),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, string $bankAccountId): JsonResponse
    {
        $v = $request->validate([
            'bankName' => ['required', 'string', 'max:255'],
            'accountName' => ['required', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:64'],
            'isDefault' => ['required', 'boolean'],
            'chartAccountId' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        return response()->json([
            'message' => 'Bank account updated successfully.',
            'data' => $this->domain->updateBankAccount($bankAccountId, $v),
        ]);
    }

    public function destroy(string $bankAccountId): JsonResponse
    {
        $this->domain->deleteBankAccount($bankAccountId);

        return response()->json(['message' => 'Bank account deleted successfully.']);
    }
}

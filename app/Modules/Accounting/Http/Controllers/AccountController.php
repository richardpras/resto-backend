<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Http\Requests\StoreAccountRequest;
use App\Modules\Accounting\Http\Requests\UpdateAccountRequest;
use App\Modules\Accounting\Http\Resources\AccountResource;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);
        $accounts = $this->accountingService->listAccounts($tenantId > 0 ? $tenantId : null);

        return response()->json([
            'data' => AccountResource::collection($accounts),
        ]);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $v = $request->validated();

        $account = $this->accountingService->createAccount([
            'tenant_id' => $v['tenantId'] ?? null,
            'code' => $v['code'],
            'name' => $v['name'],
            'type' => $v['type'],
            'subtype' => $v['subtype'] ?? null,
            'parent_id' => $v['parentId'] ?? null,
            'description' => $v['description'] ?? null,
            'is_active' => $v['active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Account created successfully.',
            'data' => new AccountResource($account),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $v = $request->validated();

        $payload = [];
        if (array_key_exists('code', $v)) {
            $payload['code'] = $v['code'];
        }
        if (array_key_exists('name', $v)) {
            $payload['name'] = $v['name'];
        }
        if (array_key_exists('type', $v)) {
            $payload['type'] = $v['type'];
        }
        if (array_key_exists('subtype', $v)) {
            $payload['subtype'] = $v['subtype'];
        }
        if (array_key_exists('parentId', $v)) {
            $payload['parent_id'] = $v['parentId'];
        }
        if (array_key_exists('description', $v)) {
            $payload['description'] = $v['description'];
        }
        if (array_key_exists('active', $v)) {
            $payload['is_active'] = $v['active'];
        }

        $updated = $this->accountingService->updateAccount($account, $payload);

        return response()->json([
            'message' => 'Account updated successfully.',
            'data' => new AccountResource($updated),
        ]);
    }

    public function destroy(Account $account): JsonResponse
    {
        $this->accountingService->deleteAccount($account);

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }
}

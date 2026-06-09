<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Modules\Accounting\Http\Resources\AccountingPostingFailureResource;
use App\Modules\Accounting\Services\AccountingPostingFailureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingPostingFailureController extends Controller
{
    public function __construct(
        private readonly AccountingPostingFailureService $failureService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $rows = $this->failureService->list(is_string($status) ? $status : null, 50);

        return response()->json([
            'data' => AccountingPostingFailureResource::collection($rows->items()),
            'meta' => [
                'currentPage' => $rows->currentPage(),
                'lastPage' => $rows->lastPage(),
                'perPage' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    public function retry(AccountingPostingFailure $accountingPostingFailure, Request $request): JsonResponse
    {
        $resolved = $this->failureService->retry($accountingPostingFailure, $request->user('api'));

        return response()->json([
            'message' => 'Posting retry succeeded.',
            'data' => new AccountingPostingFailureResource($resolved),
        ]);
    }

    public function ignore(AccountingPostingFailure $accountingPostingFailure, Request $request): JsonResponse
    {
        $ignored = $this->failureService->ignore($accountingPostingFailure, $request->user('api'));

        return response()->json([
            'message' => 'Posting failure ignored.',
            'data' => new AccountingPostingFailureResource($ignored),
        ]);
    }
}

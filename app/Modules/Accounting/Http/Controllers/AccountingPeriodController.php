<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Accounting\Domain\AccountingPeriod;
use App\Modules\Accounting\Services\AccountingPeriodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountingPeriodController extends Controller
{
    public function __construct(
        private readonly AccountingPeriodService $periodService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $periods = $this->periodService->listForActor($request->user('api'));

        return response()->json([
            'success' => true,
            'message' => 'Accounting periods fetched successfully.',
            'data' => $periods->map(fn (AccountingPeriod $p): array => $this->mapPeriod($p))->all(),
            'meta' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tenantId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'outletId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'startDate' => ['required', 'date_format:Y-m-d'],
            'endDate' => ['required', 'date_format:Y-m-d'],
        ]);
        $period = $this->periodService->create([
            'name' => $v['name'] ?? null,
            'tenant_id' => $v['tenantId'] ?? null,
            'outlet_id' => $v['outletId'] ?? null,
            'start_date' => $v['startDate'],
            'end_date' => $v['endDate'],
        ], $request->user('api'));

        return response()->json([
            'success' => true,
            'message' => 'Accounting period created successfully.',
            'data' => $this->mapPeriod($period),
            'meta' => null,
        ], Response::HTTP_CREATED);
    }

    public function close(Request $request, AccountingPeriod $period): JsonResponse
    {
        $closed = $this->periodService->close($period, $request->user('api'));

        return response()->json([
            'success' => true,
            'message' => 'Accounting period closed successfully.',
            'data' => $this->mapPeriod($closed),
            'meta' => null,
        ]);
    }

    public function open(Request $request, AccountingPeriod $period): JsonResponse
    {
        $opened = $this->periodService->open($period, $request->user('api'));

        return response()->json([
            'success' => true,
            'message' => 'Accounting period opened successfully.',
            'data' => $this->mapPeriod($opened),
            'meta' => null,
        ]);
    }

    /** @return array{id:string,name:string,startDate:string,endDate:string,status:string,outletId:?string,closedAt:?string,closedByUserId:?string} */
    private function mapPeriod(AccountingPeriod $period): array
    {
        return [
            'id' => (string) $period->id,
            'name' => (string) ($period->name ?? ''),
            'startDate' => $period->start_date?->format('Y-m-d') ?? '',
            'endDate' => $period->end_date?->format('Y-m-d') ?? '',
            'status' => (string) $period->status,
            'outletId' => $period->outlet_id !== null ? (string) $period->outlet_id : null,
            'closedAt' => $period->closed_at?->toISOString(),
            'closedByUserId' => $period->closed_by_user_id !== null ? (string) $period->closed_by_user_id : null,
        ];
    }
}

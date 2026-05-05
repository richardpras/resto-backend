<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\RunPayrollRequest;
use App\Modules\HR\Http\Resources\PayrollLineResource;
use App\Modules\HR\Http\Requests\StorePayrollRequest;
use App\Modules\HR\Http\Resources\PayrollResource;
use App\Modules\HR\Http\Resources\PayrollRunResource;
use App\Modules\HR\Services\LegacyPayrollService;
use App\Modules\HR\Services\PayrollPostingService;
use App\Modules\HR\Services\PayrollRunService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PayrollController extends Controller
{
    public function __construct(
        private readonly LegacyPayrollService $legacyService,
        private readonly PayrollRunService $runService,
        private readonly PayrollPostingService $postingService,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);

        return response()->json([
            'data' => PayrollResource::collection($this->legacyService->listByTenant($tenantId)),
        ]);
    }

    public function store(StorePayrollRequest $request): JsonResponse
    {
        $payroll = $this->legacyService->create($request->validated(), (int) $request->user()->id);

        return response()->json([
            'message' => 'Payroll posted successfully.',
            'data' => new PayrollResource($payroll),
        ], Response::HTTP_CREATED);
    }

    public function listRuns(): JsonResponse
    {
        $filters = [
            'page' => request()->query('page'),
            'perPage' => request()->query('perPage'),
            'periodFrom' => request()->query('periodFrom'),
            'periodTo' => request()->query('periodTo'),
            'outlet' => request()->query('outlet'),
            'employeeId' => request()->query('employeeId'),
            'status' => request()->query('status'),
            'search' => request()->query('search'),
        ];
        $paginator = $this->runService->listTable($filters);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    public function showDetail(int $id): JsonResponse
    {
        return response()->json([
            'data' => $this->runService->detail($id),
        ]);
    }

    public function run(RunPayrollRequest $request): JsonResponse
    {
        $run = $this->runService->run($request->validated(), (int) $request->user()->id);

        return response()->json([
            'message' => 'Payroll run calculated successfully.',
            'data' => new PayrollRunResource($run),
        ], Response::HTTP_CREATED);
    }

    public function finalize(int $id): JsonResponse
    {
        $run = $this->runService->finalize($id, (int) request()->user()->id);

        return response()->json([
            'message' => 'Payroll run finalized successfully.',
            'data' => new PayrollRunResource($run),
        ]);
    }

    public function pay(int $id): JsonResponse
    {
        $run = $this->runService->pay($id, (int) request()->user()->id);

        return response()->json([
            'message' => 'Payroll run paid successfully.',
            'data' => new PayrollRunResource($run),
        ]);
    }

    public function lockLine(int $id): JsonResponse
    {
        $line = $this->runService->lockLine($id, (int) request()->user()->id);

        return response()->json([
            'message' => 'Payroll line locked successfully.',
            'data' => new PayrollLineResource($line),
        ]);
    }

    public function unlockLine(int $id): JsonResponse
    {
        $line = $this->runService->unlockLine($id, (int) request()->user()->id);

        return response()->json([
            'message' => 'Payroll line unlocked successfully.',
            'data' => new PayrollLineResource($line),
        ]);
    }

    public function postJournal(int $id): JsonResponse
    {
        $journal = $this->postingService->postRunToJournal(
            runId: $id,
            actorUserId: (int) request()->user()->id,
            cashAccountCode: (string) request()->input('cashAccountCode', '1001'),
            salaryExpenseAccountCode: (string) request()->input('salaryExpenseAccountCode', '5001'),
        );

        return response()->json([
            'message' => 'Payroll run posted to journal successfully.',
            'data' => [
                'journalId' => (int) $journal->id,
                'journalNo' => $journal->journal_no,
            ],
        ]);
    }
}

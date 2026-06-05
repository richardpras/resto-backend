<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\PayrollRunAuditResource;
use App\Modules\HR\Http\Resources\PayrollRunV2Resource;
use App\Modules\HR\Services\PayrollClosingAnalyticsService;
use App\Modules\HR\Services\PayrollClosingService;
use App\Modules\HR\Services\PayrollRunAuditService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PayrollClosingController extends Controller
{
    public function __construct(
        private readonly PayrollClosingService $closing,
        private readonly PayrollClosingAnalyticsService $analytics,
        private readonly PayrollRunAuditService $audits,
    ) {}

    public function closingSummary(int $run): JsonResponse
    {
        $summary = $this->analytics->closingSummary($this->resolveUser(), $run);
        $auditTrail = $summary['auditTrail'];
        unset($summary['auditTrail']);

        return response()->json([
            'data' => [
                'run' => $summary['run'],
                'totals' => $summary['totals'],
                'auditTrail' => PayrollRunAuditResource::collection($auditTrail),
            ],
        ]);
    }

    public function startPayment(int $run): JsonResponse
    {
        $row = $this->closing->startPayment($this->resolveUser(), $run);
        $row->item_count = $row->items->count();

        return response()->json([
            'message' => 'Payment processing started.',
            'data' => new PayrollRunV2Resource($row),
        ]);
    }

    public function markPaid(int $run): JsonResponse
    {
        $validated = request()->validate([
            'paidAt' => ['nullable', 'date'],
        ]);

        $row = $this->closing->markPaid(
            $this->resolveUser(),
            $run,
            $validated['paidAt'] ?? null,
        );
        $row->item_count = $row->items->count();

        return response()->json([
            'message' => 'Payroll run marked as paid.',
            'data' => new PayrollRunV2Resource($row),
        ]);
    }

    public function close(int $run): JsonResponse
    {
        $validated = request()->validate([
            'notes' => ['nullable', 'string'],
        ]);

        $row = $this->closing->close(
            $this->resolveUser(),
            $run,
            $validated['notes'] ?? null,
        );
        $row->item_count = $row->items->count();

        return response()->json([
            'message' => 'Payroll run closed.',
            'data' => new PayrollRunV2Resource($row),
        ]);
    }

    public function reopen(int $run): JsonResponse
    {
        $row = $this->closing->reopen($this->resolveUser(), $run);
        $row->item_count = $row->items->count();

        return response()->json([
            'message' => 'Payroll run reopened.',
            'data' => new PayrollRunV2Resource($row),
        ]);
    }

    public function audit(int $run): JsonResponse
    {
        $this->analytics->closingSummary($this->resolveUser(), $run);
        $rows = $this->audits->listForRun($run);

        return response()->json([
            'data' => PayrollRunAuditResource::collection($rows),
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

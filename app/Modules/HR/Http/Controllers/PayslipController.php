<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Resources\PayrollPayslipResource;
use App\Modules\HR\Services\PayslipPdfService;
use App\Modules\HR\Services\PayslipService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class PayslipController extends Controller
{
    public function __construct(
        private readonly PayslipService $payslips,
        private readonly PayslipPdfService $pdf,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->payslips->list($this->resolveUser(), [
            'outletId' => request()->query('outletId'),
            'employeeId' => request()->query('employeeId'),
            'payrollRunId' => request()->query('payrollRunId'),
            'status' => request()->query('status'),
            'periodFrom' => request()->query('periodFrom'),
            'periodTo' => request()->query('periodTo'),
        ]);

        return response()->json([
            'data' => PayrollPayslipResource::collection($rows),
        ]);
    }

    public function show(int $payslip): JsonResponse
    {
        $row = $this->payslips->findAccessible($this->resolveUser(), $payslip);

        return response()->json([
            'data' => new PayrollPayslipResource($row),
        ]);
    }

    public function generate(): JsonResponse
    {
        $validated = request()->validate([
            'payrollRunId' => ['required', 'integer', 'exists:payroll_runs_v2,id'],
        ]);

        $rows = $this->payslips->generateForRun(
            $this->resolveUser(),
            (int) $validated['payrollRunId'],
        );

        return response()->json([
            'message' => 'Payslips generated.',
            'data' => PayrollPayslipResource::collection($rows),
            'meta' => ['count' => $rows->count()],
        ], Response::HTTP_CREATED);
    }

    public function publish(int $payslip): JsonResponse
    {
        $row = $this->payslips->publish($this->resolveUser(), $payslip);

        return response()->json([
            'message' => 'Payslip published.',
            'data' => new PayrollPayslipResource($row),
        ]);
    }

    public function regenerate(int $payslip): JsonResponse
    {
        $row = $this->payslips->regenerate($this->resolveUser(), $payslip);

        return response()->json([
            'message' => 'Payslip PDF regenerated.',
            'data' => new PayrollPayslipResource($row),
        ]);
    }

    public function forEmployee(int $employee): JsonResponse
    {
        $rows = $this->payslips->forEmployee($this->resolveUser(), $employee);

        return response()->json([
            'data' => PayrollPayslipResource::collection($rows),
        ]);
    }

    public function download(int $payslip): BinaryFileResponse|JsonResponse
    {
        $row = $this->payslips->findAccessible($this->resolveUser(), $payslip);
        $path = $this->pdf->absolutePath($row->pdf_path);

        if ($path === null) {
            return response()->json([
                'message' => 'PDF not available for this payslip.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->download($path, $row->payslip_no.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    private function resolveUser(): ?\App\Models\User
    {
        $user = request()->user('api') ?? request()->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}

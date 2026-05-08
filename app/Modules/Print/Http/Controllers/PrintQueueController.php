<?php

namespace App\Modules\Print\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Print\Http\Requests\RetryPrintJobRequest;
use App\Modules\Print\Http\Resources\PrintJobResource;
use App\Modules\Print\Services\PrinterManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PrintQueueController extends Controller
{
    public function __construct(
        private readonly PrinterManagementService $service,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $outletId = (int) $request->query('outletId', 0);
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return response()->json([
            'data' => $this->service->queueStatus($outletId),
        ]);
    }

    public function retry(RetryPrintJobRequest $request, int $printJob): JsonResponse
    {
        $job = $this->service->retryJob($printJob, (int) $request->validated('outletId'));

        return response()->json([
            'message' => 'Print job retry queued.',
            'data' => new PrintJobResource($job),
        ]);
    }
}

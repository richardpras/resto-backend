<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\GetOpenBillByTableRequest;
use App\Modules\Orders\Services\OpenBillAggregationService;
use Illuminate\Http\JsonResponse;

class OpenBillController extends Controller
{
    public function __construct(
        private readonly OpenBillAggregationService $openBillAggregationService,
    ) {}

    public function byTable(GetOpenBillByTableRequest $request): JsonResponse
    {
        $payload = $this->openBillAggregationService->aggregateByTable(
            $request->user(),
            (int) $request->validated('outletId'),
            (int) $request->validated('tableId'),
        );

        return response()->json([
            'data' => $payload,
        ]);
    }
}

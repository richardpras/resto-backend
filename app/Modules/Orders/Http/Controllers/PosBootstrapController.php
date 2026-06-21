<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\PosBootstrapRequest;
use App\Modules\Orders\Services\PosBootstrapService;
use Illuminate\Http\JsonResponse;

class PosBootstrapController extends Controller
{
    public function __construct(
        private readonly PosBootstrapService $service,
    ) {}

    public function show(PosBootstrapRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->build($request->user(), $request->validated()),
        ]);
    }
}

<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Http\Requests\PosOfflineBootstrapRequest;
use App\Modules\Orders\Services\PosOfflineBootstrapService;
use Illuminate\Http\JsonResponse;

class PosOfflineBootstrapController extends Controller
{
    public function __construct(
        private readonly PosOfflineBootstrapService $service,
    ) {}

    public function show(PosOfflineBootstrapRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->build($request->user(), $request->validated()),
        ]);
    }
}

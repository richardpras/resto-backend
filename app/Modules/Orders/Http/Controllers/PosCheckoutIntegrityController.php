<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Orders\Services\PosCheckoutIntegrityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosCheckoutIntegrityController extends Controller
{
    public function __construct(
        private readonly PosCheckoutIntegrityService $integrityService,
    ) {}

    public function health(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['nullable', 'integer', 'min:1'],
            'hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $outletId = isset($validated['outletId']) ? (int) $validated['outletId'] : null;
        $hours = isset($validated['hours']) ? (int) $validated['hours'] : 24;

        return response()->json([
            'data' => [
                'label' => 'Duplicate Order Prevention',
                ...$this->integrityService->summarize($outletId, $hours),
            ],
        ]);
    }
}

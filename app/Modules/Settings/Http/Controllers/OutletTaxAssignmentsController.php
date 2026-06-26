<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutletTaxAssignmentsController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function show(int $outletId): JsonResponse
    {
        return response()->json([
            'data' => [
                'outletId' => $outletId,
                'taxIds' => $this->domain->listOutletTaxAssignmentIds(request()->user(), $outletId),
            ],
        ]);
    }

    public function update(Request $request, int $outletId): JsonResponse
    {
        $validated = $request->validate([
            'taxIds' => ['present', 'array'],
            'taxIds.*' => ['string', 'max:64'],
        ]);

        $taxIds = $this->domain->syncOutletTaxAssignments(
            $request->user(),
            $outletId,
            $validated['taxIds'] ?? [],
        );

        return response()->json([
            'message' => 'Outlet tax assignments updated successfully.',
            'data' => [
                'outletId' => $outletId,
                'taxIds' => $taxIds,
            ],
        ]);
    }
}

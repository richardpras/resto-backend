<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Http\Requests\PatchOutletReceiptSettingRequest;
use App\Modules\Settings\Services\OutletReceiptSettingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OutletReceiptSettingsController extends Controller
{
    public function __construct(
        private readonly OutletReceiptSettingService $outletReceiptSettingService,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->outletReceiptSettingService->listForResponse(),
        ]);
    }

    public function update(PatchOutletReceiptSettingRequest $request, string $outletId): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->outletReceiptSettingService->updateForOutlet($outletId, [
            'receiptHeader' => $validated['receiptHeader'],
            'receiptFooter' => $validated['receiptFooter'],
            'showLogo' => $validated['showLogo'],
            'showTaxBreakdown' => $validated['showTaxBreakdown'],
        ]);

        return response()->json([
            'message' => 'Receipt settings saved successfully.',
            'data' => $data,
        ], Response::HTTP_OK);
    }
}

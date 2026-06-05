<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->domain->getSystem()]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'enableSplitBill' => ['required', 'boolean'],
            'enableMultiPayment' => ['required', 'boolean'],
            'confirmBeforePayment' => ['required', 'boolean'],
            'enableQROrdering' => ['required', 'boolean'],
            'employeeSelfServiceEnabled' => ['sometimes', 'boolean'],
        ]);

        return response()->json([
            'message' => 'System settings saved successfully.',
            'data' => $this->domain->putSystem($v),
        ]);
    }
}

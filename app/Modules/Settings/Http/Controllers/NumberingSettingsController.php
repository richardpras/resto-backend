<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NumberingSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->domain->getNumbering()]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'invoiceFormat' => ['required', 'string', 'max:128'],
            'orderFormat' => ['required', 'string', 'max:128'],
        ]);

        return response()->json([
            'message' => 'Numbering settings saved successfully.',
            'data' => $this->domain->putNumbering($v),
        ]);
    }
}

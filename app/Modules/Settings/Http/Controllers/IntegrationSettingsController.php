<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->domain->getIntegration()]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'paymentGatewayKey' => ['nullable', 'string', 'max:1024'],
            'webhookUrl' => ['nullable', 'string', 'max:2048'],
            'printAgentUrl' => ['nullable', 'string', 'max:2048'],
            'thirdPartyNotes' => ['nullable', 'string', 'max:8000'],
        ]);

        return response()->json([
            'message' => 'Integration settings saved successfully.',
            'data' => $this->domain->putIntegration($v),
        ]);
    }
}

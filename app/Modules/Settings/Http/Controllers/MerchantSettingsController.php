<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsDomainService $domain,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->domain->getMerchant()]);
    }

    public function update(Request $request): JsonResponse
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'businessType' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:2000'],
            'phone' => ['required', 'string', 'max:64'],
            'email' => ['required', 'email', 'max:255'],
            'currency' => ['required', 'string', 'max:8'],
            'timezone' => ['required', 'string', 'max:64'],
            'language' => ['required', 'string', 'max:16'],
            'logo' => ['nullable', 'string', 'max:2048'],
        ]);

        $data = $this->domain->putMerchant($v);

        return response()->json([
            'message' => 'Merchant settings saved successfully.',
            'data' => $data,
        ]);
    }
}

<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Http\Requests\UpdateSettingsRequest;
use App\Modules\Settings\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settingsService,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->settingsService->get(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        foreach (['outlets', 'taxes', 'printers', 'paymentMethods', 'banks'] as $listKey) {
            if (! array_key_exists($listKey, $validated)) {
                $validated[$listKey] = $request->input($listKey, []);
            }
        }

        $data = $this->settingsService->put($validated);

        return response()->json([
            'message' => 'Settings saved successfully.',
            'data' => $data,
        ], Response::HTTP_OK);
    }
}

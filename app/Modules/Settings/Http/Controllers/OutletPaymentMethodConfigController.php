<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\OutletPaymentMethodConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OutletPaymentMethodConfigController extends Controller
{
    public function __construct(
        private readonly OutletPaymentMethodConfigService $configService,
    ) {}

    public function index(Request $request, int $outlet): JsonResponse
    {
        $user = $request->user('api');
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        return response()->json([
            'data' => $this->configService->listConfigsForOutlet($user, $outlet),
        ]);
    }

    public function checkoutMethods(Request $request, int $outlet): JsonResponse
    {
        $user = $request->user('api');
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        return response()->json([
            'data' => $this->configService->listCheckoutMethods($user, $outlet),
        ]);
    }

    public function sync(Request $request, int $outlet): JsonResponse
    {
        $user = $request->user('api');
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $validated = $request->validate([
            'configs' => ['required', 'array'],
            'configs.*.paymentMethodCode' => ['required', 'string', 'max:64'],
            'configs.*.enabled' => ['sometimes', 'boolean'],
            'configs.*.displayOrder' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'configs.*.isDefault' => ['sometimes', 'boolean'],
            'configs.*.provider' => ['nullable', 'string', 'max:64'],
            'configs.*.settings' => ['sometimes', 'array'],
            'configs.*.settings.instructions' => ['sometimes', 'string', 'max:2000'],
        ]);

        return response()->json([
            'message' => 'Outlet payment methods updated successfully.',
            'data' => $this->configService->syncConfigs($user, $outlet, $validated['configs']),
        ]);
    }

    public function uploadStaticQrisImage(Request $request, int $outlet): JsonResponse
    {
        $user = $request->user('api');
        abort_if($user === null, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        $validated = $request->validate([
            'image' => ['required', 'file', 'image', 'max:5120'],
        ]);

        return response()->json([
            'message' => 'Static QRIS image uploaded successfully.',
            'data' => $this->configService->uploadStaticQrisImage($user, $outlet, $validated['image']),
        ]);
    }
}

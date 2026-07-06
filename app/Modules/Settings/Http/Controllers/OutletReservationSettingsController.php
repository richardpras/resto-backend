<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Http\Requests\UpdateOutletReservationSettingsRequest;
use App\Modules\Settings\Http\Resources\OutletReservationSettingResource;
use App\Modules\Settings\Services\OutletReservationSettingsService;
use Illuminate\Http\JsonResponse;

class OutletReservationSettingsController extends Controller
{
    public function __construct(
        private readonly OutletReservationSettingsService $service,
    ) {}

    public function show(int $outletId): JsonResponse
    {
        $settings = $this->service->show(request()->user(), $outletId);

        return response()->json([
            'data' => new OutletReservationSettingResource($settings),
        ]);
    }

    public function update(UpdateOutletReservationSettingsRequest $request, int $outletId): JsonResponse
    {
        $settings = $this->service->update($request->user(), $outletId, $request->validated());

        return response()->json([
            'message' => 'Outlet reservation settings updated.',
            'data' => new OutletReservationSettingResource($settings),
        ]);
    }
}

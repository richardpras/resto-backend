<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Settings\Http\Requests\UploadOutletLogoRequest;
use App\Modules\Settings\Services\OutletLogoService;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class OutletLogoController extends Controller
{
    public function __construct(
        private readonly OutletLogoService $logoService,
        private readonly SettingsDomainService $settingsDomain,
    ) {}

    public function upload(UploadOutletLogoRequest $request, int $outletId): JsonResponse
    {
        $outlet = $this->logoService->upload(
            $request->user(),
            $outletId,
            $request->file('image'),
        );

        return response()->json([
            'message' => 'Outlet logo uploaded successfully.',
            'data' => $this->settingsDomain->outletToPublicArray($outlet),
        ]);
    }

    public function destroy(int $outletId): JsonResponse
    {
        $outlet = $this->logoService->deleteLogo(request()->user(), $outletId);

        return response()->json([
            'message' => 'Outlet logo removed successfully.',
            'data' => $this->settingsDomain->outletToPublicArray($outlet),
        ]);
    }

    public function serve(Outlet $outlet): Response
    {
        $version = (int) request()->query('v', 0);
        $resolved = $this->logoService->resolveServePath($outlet, $version);
        if ($resolved === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = Storage::disk('public');
        $contents = $disk->get($resolved['path']);

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => $resolved['mime'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $resolved['etag'],
        ]);
    }
}

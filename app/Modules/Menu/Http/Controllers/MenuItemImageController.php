<?php

namespace App\Modules\Menu\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Modules\Menu\Http\Requests\UploadMenuItemImageRequest;
use App\Modules\Menu\Http\Resources\MenuItemResource;
use App\Modules\Menu\Services\MenuImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class MenuItemImageController extends Controller
{
    public function __construct(
        private readonly MenuImageService $menuImageService,
    ) {}

    public function upload(UploadMenuItemImageRequest $request, int $menuItem): JsonResponse
    {
        try {
            $updated = $this->menuImageService->upload(
                $request->user(),
                $menuItem,
                $request->file('image'),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Menu image uploaded successfully.',
            'data' => new MenuItemResource($updated),
        ]);
    }

    public function destroy(int $menuItem): JsonResponse
    {
        $updated = $this->menuImageService->deleteImage(request()->user(), $menuItem);

        return response()->json([
            'message' => 'Menu image removed successfully.',
            'data' => new MenuItemResource($updated),
        ]);
    }

    public function serve(MenuItem $menuItem): Response
    {
        $version = (int) request()->query('v', 0);
        abort_if($version < 1, Response::HTTP_NOT_FOUND);

        $resolved = $this->menuImageService->resolveServePath($menuItem, $version);
        abort_if($resolved === null, Response::HTTP_NOT_FOUND);

        $disk = Storage::disk('public');
        $absolutePath = $disk->path($resolved['path']);
        abort_if(! is_file($absolutePath), Response::HTTP_NOT_FOUND);

        return response()->file($absolutePath, [
            'Content-Type' => $resolved['mime'],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => $resolved['etag'],
        ]);
    }
}

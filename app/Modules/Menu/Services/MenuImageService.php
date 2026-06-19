<?php

namespace App\Modules\Menu\Services;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\User;
use App\Modules\Menu\Repositories\MenuRepositoryInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class MenuImageService
{
    public function __construct(
        private readonly MenuRepositoryInterface $menuRepository,
        private readonly MenuImageProcessor $processor,
    ) {}

    public function upload(User $user, int $menuItemId, UploadedFile $file): MenuItem
    {
        $menuItem = $this->menuRepository->findById($menuItemId);
        abort_if($menuItem === null, Response::HTTP_NOT_FOUND, 'Menu item not found.');
        $this->assertTenantAccess($user, (int) $menuItem->tenant_id);

        return DB::transaction(function () use ($menuItem, $file): MenuItem {
            $this->deleteStoredFiles($menuItem);

            $nextVersion = (int) $menuItem->image_version + 1;
            $encoded = $this->processor->process($file);

            $relativeDir = $this->relativeDirectory((int) $menuItem->tenant_id);
            $filename = $this->buildFilename((int) $menuItem->id, $nextVersion, $encoded['extension']);
            $path = $relativeDir.'/'.$filename;

            Storage::disk('public')->put($path, $encoded['binary']);

            $fallbackPath = null;
            if ($encoded['extension'] !== 'webp' && function_exists('imagecreatefromstring')) {
                $jpegEncoded = $this->encodeJpegFallback($encoded['binary']);
                if ($jpegEncoded !== null) {
                    $fallbackFilename = $this->buildFilename((int) $menuItem->id, $nextVersion, 'jpg');
                    $fallbackPath = $relativeDir.'/'.$fallbackFilename;
                    Storage::disk('public')->put($fallbackPath, $jpegEncoded);
                }
            }

            $menuItem->update([
                'image_path' => $path,
                'image_path_fallback' => $fallbackPath,
                'image_version' => $nextVersion,
                'image_width' => $encoded['width'],
                'image_height' => $encoded['height'],
            ]);

            return $this->menuRepository->findWithRecipes((int) $menuItem->id) ?? $menuItem->fresh();
        });
    }

    public function deleteImage(User $user, int $menuItemId): MenuItem
    {
        $menuItem = $this->menuRepository->findById($menuItemId);
        abort_if($menuItem === null, Response::HTTP_NOT_FOUND, 'Menu item not found.');
        $this->assertTenantAccess($user, (int) $menuItem->tenant_id);

        return DB::transaction(function () use ($menuItem): MenuItem {
            $this->deleteStoredFiles($menuItem);

            $menuItem->update([
                'image_path' => null,
                'image_path_fallback' => null,
                'image_version' => 0,
                'image_width' => null,
                'image_height' => null,
            ]);

            return $this->menuRepository->findWithRecipes((int) $menuItem->id) ?? $menuItem->fresh();
        });
    }

    public function publicUrl(MenuItem $menuItem): ?string
    {
        if ($menuItem->image_path === null) {
            return null;
        }

        $version = (int) $menuItem->image_version;

        return url("/api/v1/public/menu-images/{$menuItem->id}?v={$version}");
    }

    public function resolveServePath(MenuItem $menuItem, int $requestedVersion): ?array
    {
        if ($menuItem->image_path === null) {
            return null;
        }

        if ((int) $menuItem->image_version !== $requestedVersion) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = (string) $menuItem->image_path;
        if (! $disk->exists($path)) {
            $fallback = $menuItem->image_path_fallback;
            if ($fallback !== null && $disk->exists($fallback)) {
                $path = $fallback;
            } else {
                return null;
            }
        }

        $mime = str_ends_with($path, '.webp') ? 'image/webp' : 'image/jpeg';

        return [
            'path' => $path,
            'mime' => $mime,
            'etag' => '"menu-item-'.$menuItem->id.'-v'.$requestedVersion.'"',
        ];
    }

    private function assertTenantAccess(User $user, int $tenantId): void
    {
        if ($tenantId < 1) {
            return;
        }

        $userTenantId = (int) ($user->tenant_id ?? 0);
        if ($userTenantId > 0 && $userTenantId !== $tenantId) {
            abort(Response::HTTP_FORBIDDEN, 'Menu item is outside your tenant scope.');
        }
    }

    private function relativeDirectory(int $tenantId): string
    {
        return 'menu-items/t'.$tenantId;
    }

    private function buildFilename(int $menuItemId, int $version, string $extension): string
    {
        return 'item_'.$menuItemId.'_v'.$version.'.'.$extension;
    }

    private function deleteStoredFiles(MenuItem $menuItem): void
    {
        $disk = Storage::disk('public');
        foreach ([$menuItem->image_path, $menuItem->image_path_fallback] as $path) {
            if ($path !== null && $disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }

    private function encodeJpegFallback(string $binary): ?string
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = ob_get_clean();
        imagedestroy($image);

        return $jpeg === false || $jpeg === '' ? null : $jpeg;
    }
}

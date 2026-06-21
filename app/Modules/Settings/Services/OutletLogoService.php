<?php

namespace App\Modules\Settings\Services;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class OutletLogoService
{
    public function __construct(
        private readonly OutletLogoProcessor $processor,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function upload(User $user, int $outletId, UploadedFile $file): Outlet
    {
        $this->assertOutletAccess($user, $outletId);

        $outlet = Outlet::query()->whereKey($outletId)->first();
        if ($outlet === null) {
            abort(Response::HTTP_NOT_FOUND, 'Outlet not found.');
        }

        try {
            $encoded = $this->processor->process($file);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'image' => [$exception->getMessage()],
            ]);
        }

        return DB::transaction(function () use ($outlet, $encoded): Outlet {
            $this->deleteStoredFiles($outlet);

            $nextVersion = (int) $outlet->logo_version + 1;
            $relativeDir = $this->relativeDirectory((int) $outlet->id);
            $displayFilename = 'logo-v'.$nextVersion.'.'.$encoded['display']['extension'];
            $displayPath = $relativeDir.'/'.$displayFilename;

            Storage::disk('public')->put($displayPath, $encoded['display']['binary']);

            $fallbackPath = null;
            if ($encoded['display']['extension'] !== 'webp' && function_exists('imagecreatefromstring')) {
                // already jpeg path in processor fallback
            } elseif ($encoded['display']['extension'] === 'webp') {
                $jpegBinary = $this->encodeJpegFallback($encoded['display']['binary']);
                if ($jpegBinary !== null) {
                    $fallbackFilename = 'logo-v'.$nextVersion.'.jpg';
                    $fallbackPath = $relativeDir.'/'.$fallbackFilename;
                    Storage::disk('public')->put($fallbackPath, $jpegBinary);
                }
            }

            $thermalPath = $relativeDir.'/logo-v'.$nextVersion.'.thermal.json';
            Storage::disk('public')->put(
                $thermalPath,
                json_encode($encoded['thermal'], JSON_UNESCAPED_SLASHES) ?: '{}',
            );

            $outlet->update([
                'logo' => null,
                'logo_path' => $displayPath,
                'logo_path_fallback' => $fallbackPath,
                'logo_thermal_path' => $thermalPath,
                'logo_version' => $nextVersion,
            ]);

            return $outlet->fresh() ?? $outlet;
        });
    }

    public function deleteLogo(User $user, int $outletId): Outlet
    {
        $this->assertOutletAccess($user, $outletId);

        $outlet = Outlet::query()->whereKey($outletId)->first();
        if ($outlet === null) {
            abort(Response::HTTP_NOT_FOUND, 'Outlet not found.');
        }

        return DB::transaction(function () use ($outlet): Outlet {
            $this->deleteStoredFiles($outlet);
            $outlet->update([
                'logo' => null,
                'logo_path' => null,
                'logo_path_fallback' => null,
                'logo_thermal_path' => null,
                'logo_version' => 0,
            ]);

            return $outlet->fresh() ?? $outlet;
        });
    }

    public function publicUrl(?Outlet $outlet): ?string
    {
        if ($outlet === null || $outlet->logo_path === null) {
            return null;
        }

        return url('/api/v1/public/outlet-logos/'.(int) $outlet->id.'?v='.(int) $outlet->logo_version);
    }

    public function hasLogo(?Outlet $outlet): bool
    {
        return $outlet !== null && $outlet->logo_path !== null && (int) $outlet->logo_version > 0;
    }

    /**
     * @return ?array{width:int,height:int,widthBytes:int,rasterBase64:string}
     */
    public function loadThermalRaster(Outlet $outlet, string $paperKey): ?array
    {
        if ($outlet->logo_thermal_path === null) {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists((string) $outlet->logo_thermal_path)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get((string) $outlet->logo_thermal_path), true);
        if (! is_array($decoded)) {
            return null;
        }

        $entry = $decoded[$paperKey] ?? null;
        if (! is_array($entry) || ! is_string($entry['rasterBase64'] ?? null)) {
            return null;
        }

        return [
            'width' => (int) ($entry['width'] ?? 0),
            'height' => (int) ($entry['height'] ?? 0),
            'widthBytes' => (int) ($entry['widthBytes'] ?? 0),
            'rasterBase64' => (string) $entry['rasterBase64'],
        ];
    }

    public static function paperKeyForWidthChars(int $widthChars): string
    {
        return $widthChars >= 42 ? '80' : '58';
    }

    /**
     * @return ?array{path:string,mime:string,etag:string}
     */
    public function resolveServePath(Outlet $outlet, int $requestedVersion): ?array
    {
        if ($outlet->logo_path === null || (int) $outlet->logo_version !== $requestedVersion) {
            return null;
        }

        $disk = Storage::disk('public');
        $path = (string) $outlet->logo_path;
        if (! $disk->exists($path)) {
            $fallback = $outlet->logo_path_fallback;
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
            'etag' => '"outlet-logo-'.(int) $outlet->id.'-v'.$requestedVersion.'"',
        ];
    }

    private function assertOutletAccess(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            abort(Response::HTTP_FORBIDDEN, 'Outlet is outside your scope.');
        }
    }

    private function relativeDirectory(int $outletId): string
    {
        return 'outlets/'.$outletId;
    }

    private function deleteStoredFiles(Outlet $outlet): void
    {
        $disk = Storage::disk('public');
        foreach ([$outlet->logo_path, $outlet->logo_path_fallback, $outlet->logo_thermal_path] as $path) {
            if ($path !== null && $path !== '' && $disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }

    private function encodeJpegFallback(string $webpBinary): ?string
    {
        $image = @imagecreatefromstring($webpBinary);
        if ($image === false) {
            return null;
        }

        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);
        $binary = ob_get_clean();

        return is_string($binary) && $binary !== '' ? $binary : null;
    }
}

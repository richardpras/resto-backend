<?php

namespace App\Modules\Menu\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class MenuImageProcessor
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    public const TARGET_BYTES = 200 * 1024;

    public const MIN_DIMENSION = 200;

    public const LONG_EDGE_PRIMARY = 800;

    public const LONG_EDGE_SECONDARY = 640;

    /**
     * @return array{
     *     binary: string,
     *     extension: string,
     *     mime: string,
     *     width: int,
     *     height: int,
     *     bytes: int
     * }
     */
    public function process(UploadedFile $file): array
    {
        $this->assertGdAvailable();
        $this->validateUpload($file);

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new RuntimeException('Unable to read uploaded image.');
        }

        $source = @imagecreatefromstring($contents);
        if ($source === false) {
            throw new RuntimeException('Unable to decode image. Use JPEG, PNG, or WebP.');
        }

        $working = $this->resizeLongEdge($source, self::LONG_EDGE_PRIMARY);
        $encoded = $this->encodeToTarget($working);

        if ($encoded['bytes'] > self::TARGET_BYTES) {
            imagedestroy($working);
            $working = $this->resizeLongEdge($source, self::LONG_EDGE_SECONDARY);
            $encoded = $this->encodeToTarget($working);
        }

        imagedestroy($working);
        imagedestroy($source);

        return $encoded;
    }

    private function assertGdAvailable(): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required for menu image processing.');
        }
    }

    private function validateUpload(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException('Image must be 5 MB or smaller.');
        }

        $mime = (string) $file->getMimeType();
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (! in_array($mime, $allowed, true)) {
            throw new RuntimeException('Image must be JPEG, PNG, or WebP.');
        }
    }

    /**
     * @return resource
     */
    private function resizeLongEdge($image, int $longEdge)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            throw new RuntimeException(sprintf('Image must be at least %dx%d pixels.', self::MIN_DIMENSION, self::MIN_DIMENSION));
        }

        $currentLong = max($width, $height);
        if ($currentLong <= $longEdge) {
            return $this->cloneImage($image, $width, $height);
        }

        $scale = $longEdge / $currentLong;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($resized === false) {
            throw new RuntimeException('Unable to allocate image buffer.');
        }

        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $resized;
    }

    /**
     * @param resource $image
     *
     * @return resource
     */
    private function cloneImage($image, int $width, int $height)
    {
        $clone = imagecreatetruecolor($width, $height);
        if ($clone === false) {
            throw new RuntimeException('Unable to allocate image buffer.');
        }

        imagealphablending($clone, false);
        imagesavealpha($clone, true);
        imagecopy($clone, $image, 0, 0, 0, 0, $width, $height);

        return $clone;
    }

    /**
     * @param resource $image
     *
     * @return array{
     *     binary: string,
     *     extension: string,
     *     mime: string,
     *     width: int,
     *     height: int,
     *     bytes: int
     * }
     */
    private function encodeToTarget($image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (function_exists('imagewebp')) {
            $quality = 85;
            $bestBinary = '';
            while ($quality >= 60) {
                $binary = $this->captureImage($image, 'webp', $quality);
                $bestBinary = $binary;
                if (strlen($binary) <= self::TARGET_BYTES) {
                    return [
                        'binary' => $binary,
                        'extension' => 'webp',
                        'mime' => 'image/webp',
                        'width' => $width,
                        'height' => $height,
                        'bytes' => strlen($binary),
                    ];
                }
                $quality -= 5;
            }

            return [
                'binary' => $bestBinary,
                'extension' => 'webp',
                'mime' => 'image/webp',
                'width' => $width,
                'height' => $height,
                'bytes' => strlen($bestBinary),
            ];
        }

        $quality = 80;
        $bestBinary = '';
        while ($quality >= 55) {
            $binary = $this->captureImage($image, 'jpeg', $quality);
            $bestBinary = $binary;
            if (strlen($binary) <= self::TARGET_BYTES) {
                break;
            }
            $quality -= 5;
        }

        return [
            'binary' => $bestBinary,
            'extension' => 'jpg',
            'mime' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($bestBinary),
        ];
    }

    /**
     * @param resource $image
     */
    private function captureImage($image, string $format, int $quality): string
    {
        ob_start();
        if ($format === 'webp') {
            imagewebp($image, null, $quality);
        } else {
            imagejpeg($image, null, $quality);
        }
        $binary = ob_get_clean();

        if ($binary === false || $binary === '') {
            throw new RuntimeException('Failed to encode image.');
        }

        return $binary;
    }
}

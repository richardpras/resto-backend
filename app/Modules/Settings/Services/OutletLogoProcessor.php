<?php

namespace App\Modules\Settings\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class OutletLogoProcessor
{
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    public const TARGET_BYTES = 200 * 1024;

    public const MIN_DIMENSION = 200;

    public const DISPLAY_LONG_EDGE = 512;

    public const DISPLAY_LONG_EDGE_SECONDARY = 400;

    public const THERMAL_WIDTH_58 = 384;

    public const THERMAL_WIDTH_80 = 576;

    public const THERMAL_SCALE  = 0.5;

    /**
     * @return array{
     *     display: array{binary:string,extension:string,mime:string,width:int,height:int,bytes:int},
     *     thermal: array{58:array{width:int,height:int,widthBytes:int,rasterBase64:string},80:array{width:int,height:int,widthBytes:int,rasterBase64:string}}
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

        $flattened = $this->flattenOnWhite($source);
        imagedestroy($source);

        $displayImage = $this->resizeLongEdge($flattened, self::DISPLAY_LONG_EDGE);
        $display = $this->encodeDisplayToTarget($displayImage);

        if ($display['bytes'] > self::TARGET_BYTES) {
            imagedestroy($displayImage);
            $displayImage = $this->resizeLongEdge($flattened, self::DISPLAY_LONG_EDGE_SECONDARY);
            $display = $this->encodeDisplayToTarget($displayImage);
        }

        imagedestroy($displayImage);

        $thermal = [
            '58' => $this->buildThermalRaster(
                $flattened,
                (int) round(self::THERMAL_WIDTH_58 * self::THERMAL_SCALE),
            ),
            '80' => $this->buildThermalRaster(
                $flattened,
                (int) round(self::THERMAL_WIDTH_80 * self::THERMAL_SCALE),
            ),
        ];

        imagedestroy($flattened);

        return [
            'display' => $display,
            'thermal' => $thermal,
        ];
    }

    /**
     * @return array{width:int,height:int,widthBytes:int,rasterBase64:string}
     */
    private function buildThermalRaster($source, int $maxWidth): array
    {
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);
        $scale = min(1.0, $maxWidth / max(1, $srcWidth));
        $targetWidth = max(1, (int) round($srcWidth * $scale));
        $targetHeight = max(1, (int) round($srcHeight * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($resized === false) {
            throw new RuntimeException('Unable to allocate thermal image buffer.');
        }

        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $srcWidth, $srcHeight);
        imagefilter($resized, IMG_FILTER_GRAYSCALE);

        $widthBytes = (int) ceil($targetWidth / 8);
        $binary = '';

        for ($y = 0; $y < $targetHeight; $y++) {
            for ($xByte = 0; $xByte < $widthBytes; $xByte++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = ($xByte * 8) + $bit;
                    if ($x >= $targetWidth) {
                        continue;
                    }
                    $rgb = imagecolorat($resized, $x, $y);
                    $gray = ($rgb >> 16) & 0xFF;
                    if ($gray < 128) {
                        $byte |= (1 << (7 - $bit));
                    }
                }
                $binary .= chr($byte);
            }
        }

        imagedestroy($resized);

        return [
            'width' => $targetWidth,
            'height' => $targetHeight,
            'widthBytes' => $widthBytes,
            'rasterBase64' => base64_encode($binary),
        ];
    }

    /**
     * @return array{binary:string,extension:string,mime:string,width:int,height:int,bytes:int}
     */
    private function encodeDisplayToTarget($image): array
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
     * @param  resource  $image
     * @return resource
     */
    private function flattenOnWhite($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $flat = imagecreatetruecolor($width, $height);
        if ($flat === false) {
            throw new RuntimeException('Unable to allocate image buffer.');
        }

        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefill($flat, 0, 0, $white);
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, $width, $height);

        return $flat;
    }

    /**
     * @param  resource  $image
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

        $white = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $resized;
    }

    /**
     * @param  resource  $image
     * @return resource
     */
    private function cloneImage($image, int $width, int $height)
    {
        $clone = imagecreatetruecolor($width, $height);
        if ($clone === false) {
            throw new RuntimeException('Unable to allocate image buffer.');
        }

        $white = imagecolorallocate($clone, 255, 255, 255);
        imagefill($clone, 0, 0, $white);
        imagecopy($clone, $image, 0, 0, 0, 0, $width, $height);

        return $clone;
    }

    private function assertGdAvailable(): void
    {
        if (! extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required for outlet logo processing.');
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
     * @param  resource  $image
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

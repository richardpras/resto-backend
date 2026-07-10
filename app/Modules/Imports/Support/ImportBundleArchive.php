<?php

namespace App\Modules\Imports\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class ImportBundleArchive
{
    /**
     * @return array<string, string>
     */
    public static function extractCsvSheets(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            $path = $file->getPathname();
        }
        if ($path === false || ! is_readable($path)) {
            throw ValidationException::withMessages([
                'file' => ['Unable to read uploaded file.'],
            ]);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([
                'file' => ['Upload must be a valid ZIP archive containing CSV files.'],
            ]);
        }

        $sheets = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $basename = strtolower(basename($name));
            if (! str_ends_with($basename, '.csv')) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                $sheets[$basename] = $content;
            }
        }
        $zip->close();

        return $sheets;
    }
}

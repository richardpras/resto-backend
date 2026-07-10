<?php

namespace App\Modules\Imports\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

final class XlsxWorkbookParser
{
    /**
     * @return array<string, string>
     */
    public static function extractSheets(UploadedFile $file): array
    {
        $path = self::resolveReadablePath($file);

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'file' => ['Upload must be a valid XLSX workbook.'],
            ]);
        }

        $sheets = [];
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $key = self::normalizeSheetKey($worksheet->getTitle());
            if ($key === '' || $key === 'readme.txt') {
                continue;
            }

            $writer = new Csv($spreadsheet);
            $writer->setSheetIndex($spreadsheet->getIndex($worksheet));
            $writer->setDelimiter(',');
            $writer->setEnclosure('"');
            $writer->setUseBOM(false);

            ob_start();
            $writer->save('php://output');
            $content = ob_get_clean() ?: '';

            $sheets[$key] = $content;
        }

        if ($sheets === []) {
            throw ValidationException::withMessages([
                'file' => ['XLSX workbook does not contain any import sheets.'],
            ]);
        }

        return $sheets;
    }

    private static function resolveReadablePath(UploadedFile $file): string
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

        return $path;
    }

    private static function normalizeSheetKey(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace([' ', '-'], '_', $name);
        if ($name !== '' && ! str_ends_with($name, '.csv')) {
            $name .= '.csv';
        }

        return $name;
    }
}

<?php

namespace App\Modules\Imports\Support;

use Illuminate\Http\UploadedFile;

final class ImportSheetExtractor
{
    /**
     * @return array<string, string>
     */
    public static function extract(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'xlsx') {
            return XlsxWorkbookParser::extractSheets($file);
        }

        return ImportBundleArchive::extractCsvSheets($file);
    }
}

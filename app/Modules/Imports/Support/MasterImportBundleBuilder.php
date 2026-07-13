<?php

namespace App\Modules\Imports\Support;

use ZipArchive;

final class MasterImportBundleBuilder
{
    /**
     * @param  list<ImportSheetDefinition>  $sheets
     */
    public function buildZip(string $readme, array $sheets, string $prefix = 'master_import_tpl_'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            abort(500, 'Unable to create template archive.');
        }

        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Unable to open template archive.');
        }

        $zip->addFromString('README.txt', $readme);

        foreach ($sheets as $sheet) {
            $legacy = $sheet->toLegacyDefinition();
            $csv = CsvTableParser::toCsv($legacy['headers'], $legacy['examples']);
            $zip->addFromString($sheet->filename, $csv);
        }

        $zip->close();

        return $zipPath;
    }
}

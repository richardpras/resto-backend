<?php

namespace Tests\Concerns;

use App\Modules\Imports\Support\ImportTemplateSchema;

trait BuildsMasterImportTestZip
{
    /**
     * @param  array<string, list<array<string, string>>>  $fieldExamplesByFilename
     */
    protected function buildPhaseZip(string $phase, array $fieldExamplesByFilename): string
    {
        $tmp = tempnam(sys_get_temp_dir(), "{$phase}_import_test_");
        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($fieldExamplesByFilename as $filename => $fieldExamples) {
            $sheet = ImportTemplateSchema::findSheet($phase, $filename);
            if ($sheet === null) {
                continue;
            }
            $zip->addFromString($filename, $sheet->toCsvFromFieldExamples($fieldExamples));
        }

        $zip->close();

        return $zipPath;
    }
}

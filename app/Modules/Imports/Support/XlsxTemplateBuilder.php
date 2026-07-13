<?php

namespace App\Modules\Imports\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class XlsxTemplateBuilder
{
    private const EXAMPLE_ROW = 2;

    private const DATA_START_ROW = 3;

    private const MAX_DATA_ROWS = 500;

    /**
     * @param  list<ImportSheetDefinition>  $sheets
     * @param  array<string, list<string>>  $relationLists
     */
    public function build(
        string $phaseTitle,
        string $instructionsId,
        string $instructionsEn,
        array $sheets,
        array $relationLists = [],
    ): string {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $guide = new Worksheet($spreadsheet, 'Petunjuk');
        $spreadsheet->addSheet($guide, 0);
        $this->writeGuideSheet($guide, $phaseTitle, $instructionsId, $instructionsEn, $sheets);

        $listSheet = null;
        $listRanges = [];
        if ($relationLists !== []) {
            $listSheet = new Worksheet($spreadsheet, '_lists');
            $spreadsheet->addSheet($listSheet);
            $listSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
            $listRanges = $this->writeRelationListSheet($listSheet, $relationLists);
        }

        $sheetIndex = 1;
        foreach ($sheets as $definition) {
            $sheet = new Worksheet($spreadsheet, $this->truncateSheetTitle($definition->sheetTitle));
            $spreadsheet->addSheet($sheet, $sheetIndex++);
            $this->writeDataSheet($sheet, $definition, $listRanges);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $tmp = tempnam(sys_get_temp_dir(), 'master_import_xlsx_');
        if ($tmp === false) {
            abort(500, 'Unable to create template workbook.');
        }
        $path = $tmp.'.xlsx';
        @unlink($tmp);

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        return $path;
    }

    /**
     * @param  list<ImportSheetDefinition>  $sheets
     */
    private function writeGuideSheet(
        Worksheet $sheet,
        string $phaseTitle,
        string $instructionsId,
        string $instructionsEn,
        array $sheets,
    ): void {
        $sheet->setCellValue('A1', $phaseTitle);
        $sheet->setCellValue('A2', 'Petunjuk / Instructions');
        $sheet->setCellValue('A4', $instructionsId);
        $sheet->setCellValue('A5', $instructionsEn);
        $sheet->setCellValue('A7', 'Urutan sheet / Sheet order:');
        $row = 8;
        foreach ($sheets as $index => $definition) {
            $sheet->setCellValue("A{$row}", ($index + 1).'. '.$definition->sheetTitle.' — '.$definition->descriptionId.' / '.$definition->descriptionEn);
            $row++;
        }
        $sheet->setCellValue("A{$row}", '');
        $row++;
        $sheet->setCellValue("A{$row}", 'Baris kuning = contoh. Hapus sebelum commit. / Yellow row = example. Delete before commit.');
        $sheet->getColumnDimension('A')->setWidth(100);
    }

    /**
     * @param  array<string, list<string>>  $relationLists
     * @return array<string, string> relation => named range formula
     */
    private function writeRelationListSheet(Worksheet $sheet, array $relationLists): array
    {
        $ranges = [];
        $col = 1;
        foreach ($relationLists as $relation => $values) {
            if ($values === []) {
                continue;
            }
            $sheet->setCellValue([$col, 1], $relation);
            foreach ($values as $index => $value) {
                $sheet->setCellValue([$col, $index + 2], $value);
            }
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $lastRow = count($values) + 1;
            $ranges[$relation] = "'_lists'!\${$colLetter}\$2:\${$colLetter}\${$lastRow}";
            $col++;
        }

        return $ranges;
    }

    /**
     * @param  array<string, string>  $listRanges
     */
    private function writeDataSheet(Worksheet $sheet, ImportSheetDefinition $definition, array $listRanges): void
    {
        foreach ($definition->columns as $colIndex => $column) {
            $col = $colIndex + 1;
            $headerCell = $sheet->getCell([$col, 1]);
            $headerCell->setValue($column->bilingualHeader());
            $headerCell->getStyle()->getFont()->setBold(true);
            if ($column->required) {
                $headerCell->getStyle()->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFE8F4FC');
            }

            $note = $column->bilingualNote();
            if ($note !== '') {
                $cellCoord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).'1';
                $comment = $sheet->getComment($cellCoord);
                $comment->getText()->createTextRun($note);
            }

            foreach ($definition->examples as $example) {
                $sheet->setCellValue([$col, self::EXAMPLE_ROW], $example[$column->field] ?? '');
            }

            $this->applyValidation($sheet, $col, $column, $listRanges);
        }

        $sheet->getStyle('A'.self::EXAMPLE_ROW.':'.$sheet->getHighestColumn().self::EXAMPLE_ROW)
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFFFF9C4');

        $sheet->setCellValue('A'.self::EXAMPLE_ROW, ($sheet->getCell('A'.self::EXAMPLE_ROW)->getValue() ?? '').'');
        $sheet->freezePane('A'.self::DATA_START_ROW);

        $highestCol = count($definition->columns);
        for ($c = 1; $c <= $highestCol; $c++) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }

    /**
     * @param  array<string, string>  $listRanges
     */
    private function applyValidation(Worksheet $sheet, int $col, ImportColumnSpec $column, array $listRanges): void
    {
        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
        $range = "{$colLetter}".self::DATA_START_ROW.":{$colLetter}".(self::DATA_START_ROW + self::MAX_DATA_ROWS - 1);

        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $validation->setAllowBlank(true);
        $validation->setShowDropDown(true);
        $validation->setShowErrorMessage(true);

        if ($column->type === ImportColumnSpec::TYPE_ENUM && $column->enumValues !== []) {
            $validation->setFormula1('"'.implode(',', $column->enumValues).'"');
            $sheet->setDataValidation($range, $validation);

            return;
        }

        if ($column->type === ImportColumnSpec::TYPE_BOOL) {
            $validation->setFormula1('"1,0"');
            $sheet->setDataValidation($range, $validation);

            return;
        }

        if ($column->type === ImportColumnSpec::TYPE_RELATION
            && $column->relation !== null
            && isset($listRanges[$column->relation])
        ) {
            $validation->setFormula1('='.$listRanges[$column->relation]);
            $sheet->setDataValidation($range, $validation);
        }
    }

    private function truncateSheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}

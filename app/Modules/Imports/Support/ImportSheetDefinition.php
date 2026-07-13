<?php

namespace App\Modules\Imports\Support;

final class ImportSheetDefinition
{
    /**
     * @param  list<ImportColumnSpec>  $columns
     * @param  list<array<string, string>>  $examples
     */
    public function __construct(
        public readonly string $filename,
        public readonly string $sheetTitle,
        public readonly array $columns,
        public readonly array $examples,
        public readonly string $descriptionId = '',
        public readonly string $descriptionEn = '',
    ) {}

    public function sheetKey(): string
    {
        return strtolower($this->filename);
    }

    /**
     * @return array{headers: list<string>, examples: list<array<string, string>>, columnSpecs: list<ImportColumnSpec>}
     */
    public function toLegacyDefinition(): array
    {
        $headers = $this->bilingualHeaders();
        $examples = [];

        foreach ($this->examples as $example) {
            $examples[] = $this->rowFromFields($example);
        }

        return [
            'headers' => $headers,
            'examples' => $examples,
            'columnSpecs' => $this->columns,
        ];
    }

    /**
     * @return list<string>
     */
    public function bilingualHeaders(): array
    {
        return array_map(static fn (ImportColumnSpec $col): string => $col->bilingualHeader(), $this->columns);
    }

    /**
     * @return list<ImportColumnSpec>
     */
    public function columnSpecs(): array
    {
        return $this->columns;
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    public function rowFromFields(array $fields): array
    {
        $row = [];
        foreach ($this->columns as $column) {
            $row[$column->bilingualHeader()] = $fields[$column->field] ?? '';
        }

        return $row;
    }

    public function toCsvFromFieldExamples(array $fieldExamples): string
    {
        $rows = array_map(fn (array $fields): array => $this->rowFromFields($fields), $fieldExamples);

        return CsvTableParser::toCsv($this->bilingualHeaders(), $rows);
    }
}

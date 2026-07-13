<?php

namespace App\Modules\Imports\Support;

final class CsvTableParser
{
    /**
     * @param  list<ImportColumnSpec>|null  $columnSpecs
     * @return list<array{row:int,data:array<string,string>}>
     */
    public static function parse(string $content, ?array $columnSpecs = null): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        $content = self::stripBom($content);
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [];
        }

        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream);
        if ($header === false) {
            fclose($stream);

            return [];
        }

        $normalizedHeader = array_map(
            static fn ($value): string => ImportHeaderAliasResolver::resolve((string) $value, $columnSpecs),
            $header,
        );

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream)) !== false) {
            $line++;
            if (self::isBlankRow($cells)) {
                continue;
            }

            $assoc = [];
            foreach ($normalizedHeader as $index => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = trim((string) ($cells[$index] ?? ''));
            }

            $rows[] = ['row' => $line, 'data' => $assoc];
        }

        fclose($stream);

        return $rows;
    }

    public static function toCsv(array $headers, array $exampleRows = []): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return '';
        }

        fputcsv($stream, $headers);
        foreach ($exampleRows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($stream, $line);
        }

        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return self::stripBom($csv);
    }

    private static function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }

    private static function normalizeHeader(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace([' ', '-'], '_', $value);

        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }

    private static function isBlankRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}

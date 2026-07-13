<?php

namespace App\Modules\Imports\Support;

final class ImportHeaderAliasResolver
{
    /**
     * @param  list<ImportColumnSpec>  $columnSpecs
     * @return array<string, string> map normalized header key -> field
     */
    public static function buildAliasMap(array $columnSpecs): array
    {
        $map = [];
        foreach ($columnSpecs as $spec) {
            $map[$spec->field] = $spec->field;
            foreach ($spec->aliasKeys() as $alias) {
                if ($alias !== '') {
                    $map[$alias] = $spec->field;
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<ImportColumnSpec>|null  $columnSpecs
     */
    public static function resolve(string $rawHeader, ?array $columnSpecs = null): string
    {
        $parenField = self::extractFieldFromParentheses($rawHeader);
        if ($parenField !== '') {
            return $parenField;
        }

        $normalized = self::normalizeKey($rawHeader);
        if ($normalized === '') {
            return '';
        }

        if ($columnSpecs !== null) {
            $aliasMap = self::buildAliasMap($columnSpecs);
            if (isset($aliasMap[$normalized])) {
                return $aliasMap[$normalized];
            }
        }

        return $normalized;
    }

    private static function extractFieldFromParentheses(string $value): string
    {
        if (preg_match('/\(([a-z][a-z0-9_]*)\)\s*$/i', trim($value), $matches) === 1) {
            return strtolower($matches[1]);
        }

        return '';
    }

    private static function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace([' ', '-'], '_', $value);

        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }
}

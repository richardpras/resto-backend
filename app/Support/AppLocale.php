<?php

namespace App\Support;

use Illuminate\Http\Request;

final class AppLocale
{
    public const SUPPORTED = ['en', 'id'];

    public static function normalize(?string $raw): string
    {
        $value = strtolower(trim((string) $raw));
        if ($value === 'id' || str_starts_with($value, 'id-') || str_starts_with($value, 'id_')) {
            return 'id';
        }

        return 'en';
    }

    public static function fromRequest(Request $request): string
    {
        $query = $request->query('lang');
        if (is_string($query) && $query !== '') {
            return self::normalize($query);
        }

        $header = $request->header('Accept-Language');
        if (is_string($header) && $header !== '') {
            $first = explode(',', $header)[0] ?? '';

            return self::normalize($first);
        }

        return 'en';
    }
}

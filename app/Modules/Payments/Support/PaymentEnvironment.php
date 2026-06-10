<?php

namespace App\Modules\Payments\Support;

final class PaymentEnvironment
{
    /** @var list<string> */
    private const STUB_ALLOWED_ENVS = ['local', 'development', 'testing'];

    public static function allowsStubMode(): bool
    {
        $env = strtolower(trim((string) config('app.env', 'production')));

        return in_array($env, self::STUB_ALLOWED_ENVS, true);
    }

    public static function isProduction(): bool
    {
        return strtolower(trim((string) config('app.env', 'production'))) === 'production';
    }
}

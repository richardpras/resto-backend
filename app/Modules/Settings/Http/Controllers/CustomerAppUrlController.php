<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Settings\Services\CustomerAppUrlResolver;
use App\Modules\Settings\Services\SettingsDomainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class CustomerAppUrlController extends Controller
{
    public function show(CustomerAppUrlResolver $resolver): JsonResponse
    {
        $stored = trim((string) (SystemSetting::query()->value('customer_app_url') ?? ''));

        return response()->json([
            'data' => [
                'customerAppUrl' => $stored !== '' ? $stored : null,
                'resolvedCustomerAppUrl' => $resolver->resolve(),
                'source' => $this->resolveSource($stored),
            ],
        ]);
    }

    public function update(Request $request, SettingsDomainService $settings): JsonResponse
    {
        $validated = $request->validate([
            'customerAppUrl' => ['nullable', 'string', 'max:512'],
        ]);

        $raw = isset($validated['customerAppUrl']) ? trim((string) $validated['customerAppUrl']) : '';
        if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages([
                'customerAppUrl' => ['Customer App URL must be a valid URL.'],
            ]);
        }

        $settings->putCustomerAppUrl($raw !== '' ? rtrim($raw, '/') : null);

        return $this->show(app(CustomerAppUrlResolver::class));
    }

    private function resolveSource(string $stored): string
    {
        if ($stored !== '') {
            return 'customer_app_url';
        }
        if (trim((string) config('app.frontend_url', '')) !== '') {
            return 'frontend_url';
        }

        return 'app_url';
    }
}

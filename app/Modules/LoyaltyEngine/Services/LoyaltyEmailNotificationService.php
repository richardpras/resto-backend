<?php

namespace App\Modules\LoyaltyEngine\Services;

use App\Models\Modules\Settings\Domain\OutletBrevoSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LoyaltyEmailNotificationService
{
    public function isConfiguredForOutlet(int $outletId): bool
    {
        $setting = OutletBrevoSetting::query()->where('outlet_id', $outletId)->first();
        if ($setting instanceof OutletBrevoSetting && $setting->is_enabled) {
            return trim((string) ($setting->api_key ?? '')) !== ''
                && trim((string) ($setting->sender_email ?? '')) !== '';
        }

        $globalKey = trim((string) config('services.brevo.api_key', ''));
        $globalSender = trim((string) config('services.brevo.sender_email', ''));

        return $globalKey !== '' && $globalSender !== '';
    }

    public function send(
        int $outletId,
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $htmlContent,
    ): bool {
        try {
            $config = $this->resolveConfig($outletId);
            if ($config === null) {
                return false;
            }

            $response = Http::withHeaders([
                'api-key' => $config['api_key'],
                'accept' => 'application/json',
            ])->post('https://api.brevo.com/v3/smtp/email', [
                'sender' => [
                    'name' => $config['sender_name'],
                    'email' => $config['sender_email'],
                ],
                'to' => [[
                    'email' => $recipientEmail,
                    'name' => $recipientName,
                ]],
                'subject' => $subject,
                'htmlContent' => $htmlContent,
            ]);

            if (! $response->successful()) {
                Log::warning('loyalty.notification.email_failed', [
                    'outletId' => $outletId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('loyalty.notification.email_exception', [
                'outletId' => $outletId,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return array{api_key: string, sender_email: string, sender_name: string}|null
     */
    private function resolveConfig(int $outletId): ?array
    {
        $setting = OutletBrevoSetting::query()->where('outlet_id', $outletId)->first();
        if ($setting instanceof OutletBrevoSetting && $setting->is_enabled) {
            $apiKey = trim((string) ($setting->api_key ?? ''));
            $senderEmail = trim((string) ($setting->sender_email ?? ''));
            if ($apiKey !== '' && $senderEmail !== '') {
                return [
                    'api_key' => $apiKey,
                    'sender_email' => $senderEmail,
                    'sender_name' => trim((string) ($setting->sender_name ?? '')) ?: 'Loyalty',
                ];
            }
        }

        $apiKey = trim((string) config('services.brevo.api_key', ''));
        $senderEmail = trim((string) config('services.brevo.sender_email', ''));
        if ($apiKey === '' || $senderEmail === '') {
            return null;
        }

        return [
            'api_key' => $apiKey,
            'sender_email' => $senderEmail,
            'sender_name' => trim((string) config('services.brevo.sender_name', '')) ?: 'Loyalty',
        ];
    }
}

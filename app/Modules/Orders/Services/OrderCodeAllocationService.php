<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Print\Domain\InvoiceSequence;
use App\Models\Modules\Settings\Domain\NumberingSetting;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Settings\Services\SettingsDomainService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class OrderCodeAllocationService
{
    private const DEFAULT_FORMAT = 'ORD-{YYYY}{MM}{DD}-{000}';

    public function __construct(
        private readonly SettingsDomainService $settingsDomainService,
    ) {}

    /** @return array{code: string, preview: bool} */
    public function preview(int $outletId): array
    {
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return [
            'code' => $this->buildCode($outletId, consume: false),
            'preview' => true,
        ];
    }

    public function allocate(int $outletId): string
    {
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');

        return $this->buildCode($outletId, consume: true);
    }

    public function shouldAllocateServerCode(string $code): bool
    {
        $trimmed = strtoupper(trim($code));

        return $trimmed === '' || $trimmed === 'AUTO';
    }

    private function buildCode(int $outletId, bool $consume): string
    {
        return DB::transaction(function () use ($outletId, $consume): string {
            $now = now();
            $dateKey = $now->format('Ymd');
            $seriesKey = 'ORD:'.$dateKey;
            $format = $this->resolveOrderFormat($outletId);
            $padLength = $this->extractCounterPadLength($format);

            /** @var InvoiceSequence $seq */
            $seq = InvoiceSequence::query()->firstOrCreate(
                [
                    'outlet_id' => $outletId,
                    'series_key' => $seriesKey,
                ],
                [
                    'prefix' => 'ORD',
                    'pad_length' => $padLength,
                    'next_value' => 1,
                ],
            );

            $seq = InvoiceSequence::query()->whereKey((int) $seq->id)->lockForUpdate()->firstOrFail();
            if ((int) $seq->pad_length !== $padLength) {
                $seq->pad_length = $padLength;
                $seq->save();
            }

            $sequenceValue = (int) $seq->next_value;
            if ($consume) {
                $seq->next_value = $sequenceValue + 1;
                $seq->save();
            }

            return $this->formatCode($format, $now, $sequenceValue, $padLength);
        });
    }

    private function resolveOrderFormat(int $outletId): string
    {
        $numbering = $this->settingsDomainService->getNumbering();
        $format = trim((string) ($numbering['orderFormat'] ?? ''));

        if ($format === '') {
            $format = self::DEFAULT_FORMAT;
        }

        $outlet = Outlet::query()->whereKey($outletId)->first(['order_prefix']);
        $orderPrefix = trim((string) ($outlet?->order_prefix ?? ''));
        if ($orderPrefix !== '') {
            $format = preg_replace('/^ORD\b/i', $orderPrefix, $format, 1) ?? $format;
        }

        return $format;
    }

    private function extractCounterPadLength(string $format): int
    {
        if (preg_match('/\{(0+)\}/', $format, $matches) === 1) {
            return max(1, strlen($matches[1]));
        }

        return 3;
    }

    private function formatCode(string $format, CarbonInterface $now, int $sequenceValue, int $padLength): string
    {
        $padded = str_pad((string) $sequenceValue, $padLength, '0', STR_PAD_LEFT);

        $replacements = [
            '{YYYY}' => $now->format('Y'),
            '{MM}' => $now->format('m'),
            '{DD}' => $now->format('d'),
        ];

        $code = str_replace(array_keys($replacements), array_values($replacements), $format);

        return (string) preg_replace('/\{0+\}/', $padded, $code, 1);
    }
}

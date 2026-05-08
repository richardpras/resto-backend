<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\FiscalInvoice;
use App\Models\Modules\Print\Domain\InvoiceSequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FiscalInvoiceIssuer
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function issueOrReuse(int $outletId, string $sourceType, int $sourceId, array $metadata = []): FiscalInvoice
    {
        return DB::transaction(function () use ($outletId, $sourceType, $sourceId, $metadata): FiscalInvoice {
            $existing = FiscalInvoice::query()
                ->where('outlet_id', $outletId)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            /** @var InvoiceSequence $seq */
            $seq = InvoiceSequence::query()->firstOrCreate(
                [
                    'outlet_id' => $outletId,
                    'series_key' => 'INV',
                ],
                [
                    'prefix' => 'INV',
                    'pad_length' => 6,
                    'next_value' => 1,
                ]
            );

            $seq = InvoiceSequence::query()->whereKey((int) $seq->id)->lockForUpdate()->firstOrFail();
            $sequenceValue = (int) $seq->next_value;
            $seq->next_value = $sequenceValue + 1;
            $seq->save();

            $padded = str_pad((string) $sequenceValue, max(1, (int) $seq->pad_length), '0', STR_PAD_LEFT);
            $invoiceNumber = (string) $seq->prefix.'-'.$outletId.'-'.$padded;

            return FiscalInvoice::query()->create([
                'outlet_id' => $outletId,
                'fiscal_uuid' => (string) Str::uuid(),
                'invoice_number' => $invoiceNumber,
                'invoice_sequence_id' => (int) $seq->id,
                'sequence_value' => $sequenceValue,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'metadata' => $metadata,
                'issued_at' => now(),
            ]);
        });
    }
}

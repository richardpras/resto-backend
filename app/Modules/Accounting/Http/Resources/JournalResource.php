<?php

namespace App\Modules\Accounting\Http\Resources;

use App\Models\Modules\Accounting\Domain\Journal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Journal */
class JournalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entries = $this->whenLoaded('entries', fn () => $this->entries, collect());

        return [
            'id' => (string) $this->id,
            'journalNo' => $this->journal_no,
            'date' => $this->journal_date instanceof \DateTimeInterface
                ? $this->journal_date->format('Y-m-d')
                : (string) $this->journal_date,
            'reference' => $this->journal_no,
            'description' => $this->description ?? '',
            'outlet' => $this->outlet ?? 'Main Outlet',
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'status' => $this->status === 'posted' ? 'posted' : 'draft',
            'lines' => $entries->map(fn ($e) => [
                'id' => (string) $e->id,
                'accountId' => (string) $e->account_id,
                'debit' => (float) $e->debit,
                'credit' => (float) $e->credit,
                'memo' => $e->memo,
            ])->values()->all(),
        ];
    }
}

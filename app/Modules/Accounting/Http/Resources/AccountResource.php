<?php

namespace App\Modules\Accounting\Http\Resources;

use App\Models\Modules\Accounting\Domain\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Account */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'scope' => $this->scope ?? 'global',
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'type' => $this->type,
            'category' => $this->category,
            'subtype' => $this->subtype ?? $this->defaultSubtype(),
            'parentId' => $this->parent_id !== null ? (string) $this->parent_id : null,
            'description' => $this->description,
            'config' => $this->config,
            'active' => (bool) $this->is_active,
        ];
    }

    private function defaultSubtype(): string
    {
        return match ($this->type) {
            'asset' => 'current_asset',
            'liability' => 'short_term_liability',
            'equity' => 'equity',
            'revenue' => 'revenue',
            'expense' => 'expense',
            default => 'expense',
        };
    }
}

<?php

namespace App\Modules\Suppliers\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'contact' => $this->contact ?? '',
            'email' => $this->email ?? '',
            'address' => $this->address ?? '',
            'notes' => $this->notes,
            'status' => $this->status,
            'paymentTermDays' => $this->payment_term_days,
            'leadTimeDays' => $this->lead_time_days,
            'taxNumber' => $this->tax_number,
            'taxName' => $this->tax_name,
            'taxAddress' => $this->tax_address,
            'contactPerson' => $this->contact_person,
            'contactPhone' => $this->contact_phone,
            'contactEmail' => $this->contact_email,
            'isActive' => (bool) ($this->is_active ?? ($this->status === 'active')),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}

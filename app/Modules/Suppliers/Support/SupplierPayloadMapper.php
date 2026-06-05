<?php

namespace App\Modules\Suppliers\Support;

final class SupplierPayloadMapper
{
    /** @param array<string, mixed> $data */
    public static function toAttributes(array $data): array
    {
        $attributes = [];

        $map = [
            'name' => 'name',
            'contact' => 'contact',
            'email' => 'email',
            'address' => 'address',
            'notes' => 'notes',
            'status' => 'status',
            'paymentTermDays' => 'payment_term_days',
            'leadTimeDays' => 'lead_time_days',
            'taxNumber' => 'tax_number',
            'taxName' => 'tax_name',
            'taxAddress' => 'tax_address',
            'contactPerson' => 'contact_person',
            'contactPhone' => 'contact_phone',
            'contactEmail' => 'contact_email',
            'isActive' => 'is_active',
        ];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        if (array_key_exists('status', $data) && ! array_key_exists('isActive', $data)) {
            $attributes['is_active'] = $data['status'] === 'active';
        }

        if (array_key_exists('isActive', $data) && ! array_key_exists('status', $data)) {
            $attributes['status'] = $data['isActive'] ? 'active' : 'inactive';
        }

        return $attributes;
    }
}

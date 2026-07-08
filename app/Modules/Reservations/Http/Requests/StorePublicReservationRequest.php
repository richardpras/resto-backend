<?php

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePublicReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $minParty = (int) config('reservations.party_size_min', 1);
        $maxParty = (int) config('reservations.party_size_max', 50);

        return [
            'customerName' => ['required', 'string', 'max:120'],
            'customerPhone' => ['nullable', 'string', 'max:40'],
            'partySize' => ['required', 'integer', 'min:'.$minParty, 'max:'.$maxParty],
            'reservationAt' => ['required', 'date', 'after:now'],
            'items' => ['nullable', 'array'],
            'items.*.menuItemId' => ['required_with:items', 'integer', 'min:1'],
            'items.*.qty' => ['required_with:items', 'numeric', 'min:0.01'],
        ];
    }
}

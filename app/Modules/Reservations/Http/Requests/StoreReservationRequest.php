<?php

namespace App\Modules\Reservations\Http\Requests;

use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedOutletIds = $this->allowedOutletIds();
        $mustBeAllowedOutlet = static function (string $attribute, mixed $value, \Closure $fail) use ($allowedOutletIds): void {
            if (! in_array((int) $value, $allowedOutletIds, true)) {
                $fail('The selected '.$attribute.' is invalid.');
            }
        };

        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id', $mustBeAllowedOutlet],
            'customerName' => ['required', 'string', 'max:120'],
            'customerPhone' => ['nullable', 'string', 'max:40'],
            'partySize' => ['required', 'integer', 'min:1', 'max:100'],
            'reservationAt' => ['required', 'date'],
        ];
    }

    /** @return list<int> */
    private function allowedOutletIds(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        return app(OutletAccessResolver::class)->allowedOutletIds($user);
    }
}

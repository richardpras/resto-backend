<?php

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestaurantTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $outletId = (int) $this->input('outletId', 0);
        $allowedOutletIds = $this->allowedOutletIds();
        $mustBeAllowedOutlet = static function (string $attribute, mixed $value, \Closure $fail) use ($allowedOutletIds): void {
            if (! in_array((int) $value, $allowedOutletIds, true)) {
                $fail('The selected '.$attribute.' is invalid.');
            }
        };

        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id', $mustBeAllowedOutlet],
            'code' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('tables', 'code')->where(fn ($q) => $q->where('outlet_id', $outletId)),
            ],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('tables', 'name')->where(fn ($q) => $q->where('outlet_id', $outletId)),
            ],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:999'],
            'zone' => ['nullable', 'string', 'max:120'],
            'status' => ['required', 'in:active,inactive'],
            'active' => ['sometimes', 'boolean'],
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

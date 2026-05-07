<?php

namespace App\Modules\Settings\Http\Requests;

use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOutletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $outletId = (int) $this->route('outletId');
        $allowedOutletIds = $this->allowedOutletIds();

        $mustBeAllowedOutlet = static function (string $attribute, mixed $value, \Closure $fail) use ($allowedOutletIds): void {
            if (! in_array((int) $value, $allowedOutletIds, true)) {
                $fail('The selected '.$attribute.' is invalid.');
            }
        };

        return [
            'code' => ['nullable', 'string', 'max:64', Rule::unique('outlets', 'code')->ignore($outletId, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:64'],
            'manager' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,inactive'],
            'logo' => ['nullable', 'string', 'max:2048'],
            'invoicePrefix' => ['nullable', 'string', 'max:64'],
            'orderPrefix' => ['nullable', 'string', 'max:64'],
            'outlet_id' => ['sometimes', 'integer', 'min:1', $mustBeAllowedOutlet],
            'outletId' => ['sometimes', 'integer', 'min:1', $mustBeAllowedOutlet],
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

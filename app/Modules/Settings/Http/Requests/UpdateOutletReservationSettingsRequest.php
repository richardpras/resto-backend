<?php

namespace App\Modules\Settings\Http\Requests;

use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOutletReservationSettingsRequest extends FormRequest
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
            'publicEnabled' => ['sometimes', 'boolean'],
            'publicSlug' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'depositMode' => ['sometimes', Rule::in(['percent', 'flat'])],
            'depositPercent' => ['nullable', 'numeric', 'min:0.01', 'max:100'],
            'depositFlatAmount' => ['nullable', 'numeric', 'min:0.01'],
            'preorderRequired' => ['sometimes', 'boolean'],
            'depositInstructions' => ['nullable', 'string', 'max:5000'],
            'depositReviewTimeoutHours' => ['nullable', 'integer', 'min:1', 'max:168'],
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

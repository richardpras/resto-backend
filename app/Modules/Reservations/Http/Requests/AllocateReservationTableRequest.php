<?php

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AllocateReservationTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tableId' => ['required_without:tableIds', 'integer', 'min:1'],
            'tableIds' => ['required_without:tableId', 'array', 'min:1'],
            'tableIds.*' => ['integer', 'min:1'],
        ];
    }
}

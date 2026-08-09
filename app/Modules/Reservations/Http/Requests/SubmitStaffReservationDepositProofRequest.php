<?php

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitStaffReservationDepositProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) config('reservations.deposit_proof_max_kb', 5120);

        return [
            'proof' => [
                'required',
                'file',
                'max:'.$maxKb,
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
            ],
        ];
    }
}

<?php

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubmitReservationDepositProofRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) config('reservations.deposit_proof_max_kb', 5120);
        $mimes = config('reservations.deposit_proof_mimes', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);

        return [
            'proof' => ['required', 'file', 'max:'.$maxKb, 'mimes:'.implode(',', $mimes)],
        ];
    }
}

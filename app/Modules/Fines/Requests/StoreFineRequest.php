<?php

namespace App\Modules\Fines\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'citizen_id' => ['required', 'integer', 'exists:users,id'],
            'license_id' => ['nullable', 'integer', 'exists:licenses,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:2000'],
            // Currency is server-assigned from config('payment.fine_currency'); clients must not send it.
            'currency' => ['prohibited'],
        ];
    }
}

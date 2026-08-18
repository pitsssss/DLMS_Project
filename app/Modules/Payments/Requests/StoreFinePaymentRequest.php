<?php

namespace App\Modules\Payments\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFinePaymentRequest extends FormRequest
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
            'metadata' => ['sometimes', 'array'],
            'amount' => ['prohibited'],
            'currency' => ['prohibited'],
            'fine_id' => ['prohibited'],
        ];
    }
}

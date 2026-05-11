<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', 'exists:users,email'],
            'code' => ['required', 'string', 'size:6'],
            'purpose' => ['sometimes', Rule::in([\App\Enums\OtpPurpose::Register->value])],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('purpose')) {
            $this->merge(['purpose' => \App\Enums\OtpPurpose::Register->value]);
        }
    }
}

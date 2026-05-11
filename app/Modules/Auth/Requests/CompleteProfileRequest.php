<?php

namespace App\Modules\Auth\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'national_id' => [
                'required',
                'string',
                'max:64',
                Rule::unique('users', 'national_id')->ignore($this->user()?->id),
            ],
            'birth_date' => ['required', 'date', 'before:today'],
            'governorate' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:1000'],
        ];
    }
}

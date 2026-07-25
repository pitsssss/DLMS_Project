<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDashboardCitizenRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'unique:users,phone'],
            'national_id' => ['nullable', 'string', 'max:64', 'unique:users,national_id'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

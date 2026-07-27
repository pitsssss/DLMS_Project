<?php

namespace App\Modules\Dashboard\Requests;

use App\Enums\ProfileStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardCitizenIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search')) {
            $this->merge(['search' => trim((string) $this->input('search', ''))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active'      => ['sometimes', 'nullable', 'boolean'],
            'profile_status' => ['sometimes', 'nullable', Rule::enum(ProfileStatus::class)],
            'per_page'       => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}

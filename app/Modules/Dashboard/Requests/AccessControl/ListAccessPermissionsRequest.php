<?php

namespace App\Modules\Dashboard\Requests\AccessControl;

use Illuminate\Foundation\Http\FormRequest;

class ListAccessPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'module' => ['sometimes', 'nullable', 'string', 'max:50'],
            'risk_level' => ['sometimes', 'nullable', 'in:normal,sensitive,critical'],
            'assignable' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}

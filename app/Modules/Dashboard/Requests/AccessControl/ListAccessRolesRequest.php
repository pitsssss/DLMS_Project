<?php

namespace App\Modules\Dashboard\Requests\AccessControl;

use Illuminate\Foundation\Http\FormRequest;

class ListAccessRolesRequest extends FormRequest
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
            'is_system' => ['sometimes', 'nullable', 'boolean'],
            'is_assignable' => ['sometimes', 'nullable', 'boolean'],
            'is_archived' => ['sometimes', 'nullable', 'boolean'],
            'permission_module' => ['sometimes', 'nullable', 'string', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}

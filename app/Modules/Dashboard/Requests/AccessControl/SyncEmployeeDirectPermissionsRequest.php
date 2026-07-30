<?php

namespace App\Modules\Dashboard\Requests\AccessControl;

use Illuminate\Foundation\Http\FormRequest;

class SyncEmployeeDirectPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'password_confirmation' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

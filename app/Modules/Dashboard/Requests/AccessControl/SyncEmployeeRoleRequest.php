<?php

namespace App\Modules\Dashboard\Requests\AccessControl;

use Illuminate\Foundation\Http\FormRequest;

class SyncEmployeeRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'password_confirmation' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

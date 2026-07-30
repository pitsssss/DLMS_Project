<?php

namespace App\Modules\Dashboard\Requests\AccessControl;

use Illuminate\Foundation\Http\FormRequest;

class ArchiveAccessRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }
}

<?php

namespace App\Modules\Dashboard\Requests\EmployeeSessions;

use Illuminate\Foundation\Http\FormRequest;

class RevokeAllEmployeeSessionsRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'include_current_actor_session' => ['sometimes', 'boolean'],
            'password_confirmation' => ['sometimes', 'nullable', 'string'],
        ];
    }
}

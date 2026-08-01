<?php

namespace App\Modules\Dashboard\Requests\EmployeeSessions;

use Illuminate\Foundation\Http\FormRequest;

class RevokeEmployeeSessionRequest extends FormRequest
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
            'password_confirmation' => ['required', 'string'],
            'confirm_current_session' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Never log password_confirmation.
     *
     * @return list<string>
     */
    public function attributes(): array
    {
        return [
            'reason' => __('validation.attributes.reason'),
            'password_confirmation' => __('validation.attributes.password_confirmation'),
        ];
    }
}

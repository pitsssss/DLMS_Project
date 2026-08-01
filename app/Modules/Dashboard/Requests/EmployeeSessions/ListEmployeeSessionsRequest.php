<?php

namespace App\Modules\Dashboard\Requests\EmployeeSessions;

use Illuminate\Foundation\Http\FormRequest;

class ListEmployeeSessionsRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'role' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,idle,expired,logged_out,revoked'],
            'device_type' => ['sometimes', 'nullable', 'string', 'in:desktop,mobile,tablet,unknown'],
            'operating_system' => ['sometimes', 'nullable', 'string', 'max:64'],
            'browser' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ip_address' => ['sometimes', 'nullable', 'string', 'max:45'],
            'logged_in_from' => ['sometimes', 'nullable', 'date'],
            'logged_in_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:logged_in_from'],
            'last_seen_from' => ['sometimes', 'nullable', 'date'],
            'last_seen_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:last_seen_from'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.(int) config('employee_sessions.max_per_page', 100)],
            'sort' => ['sometimes', 'nullable', 'string', 'in:last_seen_desc,logged_in_desc,logged_in_asc'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->validated();
    }
}

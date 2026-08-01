<?php

namespace App\Modules\Dashboard\Requests\EmployeeSessions;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatEmployeeSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Heartbeat accepts no client-controlled lifecycle fields.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

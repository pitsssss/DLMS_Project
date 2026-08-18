<?php

namespace App\Modules\Dashboard\Requests;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardReportFilterRequest extends FormRequest
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
            'period' => ['sometimes', 'string', Rule::in(['7d', '30d', '90d', '12m', 'custom'])],
            'date_from' => ['required_if:period,custom', 'nullable', 'date'],
            'date_to' => ['required_if:period,custom', 'nullable', 'date', 'after_or_equal:date_from'],
            'group_by' => ['sometimes', 'string', Rule::in(['auto', 'day', 'week', 'month'])],
            'application_status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(ApplicationStatus::cases(), 'value'))],
            'service_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:service_types,code'],
            'license_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:license_types,code'],
            'test_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:test_types,code'],
            'test_result' => ['sometimes', 'nullable', 'string', Rule::in(array_column(TestResultStatus::cases(), 'value'))],
            'appointment_status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(AppointmentStatus::cases(), 'value'))],
            'payment_status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(PaymentStatus::cases(), 'value'))],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'document_status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(DocumentStatus::cases(), 'value'))],
            'fine_status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(FineStatus::cases(), 'value'))],
            'violation_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(LicenseStatus::cases(), 'value'))],
            'role' => ['sometimes', 'nullable', 'string', 'max:64'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return array_merge([
            'period' => '30d',
            'group_by' => 'auto',
            'page' => 1,
            'per_page' => 20,
        ], $validated);
    }
}

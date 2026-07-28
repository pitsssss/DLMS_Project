<?php

namespace App\Modules\Dashboard\Requests;

use App\Enums\LicenseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDashboardLicensesRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(LicenseStatus::cases(), 'value'))],
            'license_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:license_types,code'],
            'service_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:service_types,code'],
            'issue_date_from' => ['sometimes', 'nullable', 'date'],
            'issue_date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:issue_date_from'],
            'expiry_date_from' => ['sometimes', 'nullable', 'date'],
            'expiry_date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:expiry_date_from'],
            'expiry_filter' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in([
                    'all',
                    'active',
                    'expired',
                    'expiring_soon',
                    'expires_within_30_days',
                    'expires_within_60_days',
                    'expires_within_90_days',
                ]),
            ],
            'issued_by' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_merge([
            'page' => 1,
            'per_page' => 20,
            'expiry_filter' => 'all',
        ], $this->validated());
    }
}

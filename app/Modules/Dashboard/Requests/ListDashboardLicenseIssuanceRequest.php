<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListDashboardLicenseIssuanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search') && is_string($this->input('search'))) {
            $this->merge(['search' => trim($this->input('search'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_type_id' => ['sometimes', 'nullable', 'integer', 'exists:service_types,id'],
            'service_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:service_types,code'],
            'license_type_id' => ['sometimes', 'nullable', 'integer', 'exists:license_types,id'],
            'license_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:license_types,code'],
            'date' => ['sometimes', 'nullable', 'date'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search' => __('validation.attributes.search'),
            'service_type_id' => __('validation.attributes.service_type_id'),
            'service_type_code' => __('validation.attributes.service_type_code'),
            'license_type_id' => __('validation.attributes.license_type_id'),
            'license_type_code' => __('validation.attributes.license_type_code'),
            'date' => __('validation.attributes.date'),
            'date_from' => __('validation.attributes.date_from'),
            'date_to' => __('validation.attributes.date_to'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $validated = $this->validated();

        $search = isset($validated['search']) ? trim((string) $validated['search']) : null;
        if ($search === '') {
            $search = null;
        }

        return [
            'search' => $search,
            'service_type_id' => isset($validated['service_type_id']) ? (int) $validated['service_type_id'] : null,
            'service_type_code' => $validated['service_type_code'] ?? null,
            'license_type_id' => isset($validated['license_type_id']) ? (int) $validated['license_type_id'] : null,
            'license_type_code' => $validated['license_type_code'] ?? null,
            'date' => $validated['date'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ];
    }
}

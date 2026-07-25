<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardDocumentReviewIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('review_status')) {
            $this->merge(['review_status' => 'awaiting_review']);
        }

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
            'service_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:service_types,code'],
            'license_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:license_types,code'],
            'review_status' => ['sometimes', 'nullable', 'string', 'in:awaiting_review,completed,late,reupload_required'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search' => __('validation.attributes.search'),
            'service_type_code' => __('validation.attributes.service_type_code'),
            'license_type_code' => __('validation.attributes.license_type_code'),
            'review_status' => __('validation.attributes.review_status'),
            'per_page' => __('validation.attributes.per_page'),
        ];
    }
}

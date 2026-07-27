<?php

namespace App\Modules\Dashboard\Requests;

use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardPaymentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('search')) {
            $this->merge(['search' => trim((string) $this->input('search', ''))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(PaymentStatus::class)],
            'provider' => ['sometimes', 'nullable', 'string', Rule::in(['mock', 'stripe'])],
            'service_type_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'fee_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ];
    }
}

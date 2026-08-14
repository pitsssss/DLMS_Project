<?php

namespace App\Modules\Dashboard\Requests;

use App\Enums\AppointmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDashboardTestAppointmentsRequest extends FormRequest
{
    public const STATUS_WAITING_RESULT = 'waiting_result';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('status')) {
            $this->merge(['status' => self::STATUS_WAITING_RESULT]);
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in($this->allowedStatuses())],
            'test_type_id' => ['sometimes', 'nullable', 'integer', 'exists:test_types,id'],
            'test_type_code' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:test_types,code'],
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
            'status' => __('validation.attributes.status'),
            'test_type_id' => __('validation.attributes.test_type_id'),
            'test_type_code' => __('validation.attributes.test_type_code'),
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
            'status' => $validated['status'] ?? self::STATUS_WAITING_RESULT,
            'test_type_id' => isset($validated['test_type_id']) ? (int) $validated['test_type_id'] : null,
            'test_type_code' => $validated['test_type_code'] ?? null,
            'date' => $validated['date'] ?? null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ];
    }

    /**
     * @return list<string>
     */
    private function allowedStatuses(): array
    {
        return array_merge(
            [self::STATUS_WAITING_RESULT],
            array_column(AppointmentStatus::cases(), 'value')
        );
    }
}

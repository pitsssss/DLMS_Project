<?php

namespace App\Modules\Dashboard\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListDashboardAppointmentSlotsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'test_type_id' => ['nullable', 'integer', 'exists:test_types,id'],
            'test_type_code' => ['nullable', 'string', 'max:255'],
            'appointment_center_id' => ['nullable', 'integer', 'exists:appointment_centers,id'],
            'is_active' => ['nullable', 'boolean'],
            'availability' => ['nullable', 'string', Rule::in(['available', 'full', 'inactive', 'past', 'upcoming'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 25, 50, 100])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('date_from');
            $to = $this->input('date_to');

            if ($from && $to) {
                $fromDate = Carbon::parse((string) $from);
                $toDate = Carbon::parse((string) $to);
                if ($fromDate->diffInDays($toDate) > 366) {
                    $validator->errors()->add('date_to', __('messages.appointment_slots.validation.date_range_too_large'));
                }
            }
        });
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

        $isActive = null;
        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $isActive = filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return [
            'search' => $search,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'test_type_id' => isset($validated['test_type_id']) ? (int) $validated['test_type_id'] : null,
            'test_type_code' => $validated['test_type_code'] ?? null,
            'appointment_center_id' => array_key_exists('appointment_center_id', $validated)
                ? (int) $validated['appointment_center_id']
                : null,
            'is_active' => $isActive,
            'availability' => $validated['availability'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ];
    }
}

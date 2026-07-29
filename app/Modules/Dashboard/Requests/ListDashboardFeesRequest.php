<?php

namespace App\Modules\Dashboard\Requests;

use App\Modules\Payments\Support\ApplicationFeeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDashboardFeesRequest extends FormRequest
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
            'code' => ['nullable', 'string', Rule::in(ApplicationFeeCatalog::catalogCodes())],
            'is_active' => ['nullable', 'boolean'],
            'currency' => ['nullable', 'string', Rule::in(ApplicationFeeCatalog::allowedCurrencies())],
            'license_type_code' => ['nullable', 'string', 'max:255'],
            'service_type_code' => ['nullable', 'string', 'max:255'],
            'test_type_code' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 25, 50, 100])],
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

        $isActive = null;
        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $isActive = filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return [
            'search' => $search,
            'code' => $validated['code'] ?? null,
            'is_active' => $isActive,
            'currency' => isset($validated['currency']) ? strtoupper((string) $validated['currency']) : null,
            'license_type_code' => $validated['license_type_code'] ?? null,
            'service_type_code' => $validated['service_type_code'] ?? null,
            'test_type_code' => $validated['test_type_code'] ?? null,
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ];
    }
}

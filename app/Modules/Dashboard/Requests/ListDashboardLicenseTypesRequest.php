<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDashboardLicenseTypesRequest extends FormRequest
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
            'is_active' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 25, 50])],
            'sort_by' => ['nullable', 'string', Rule::in(['name', 'code', 'created_at'])],
            'sort_direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'search' => 'البحث',
            'is_active' => 'حالة التفعيل',
            'page' => 'رقم الصفحة',
            'per_page' => 'عدد العناصر في الصفحة',
            'sort_by' => 'عمود الترتيب',
            'sort_direction' => 'اتجاه الترتيب',
        ];
    }

    /**
     * @return array{
     *     search: string|null,
     *     is_active: bool|null,
     *     sort_by: string,
     *     sort_direction: string,
     *     per_page: int
     * }
     */
    public function filters(): array
    {
        $validated = $this->validated();

        $search = array_key_exists('search', $validated) && $validated['search'] !== null
            ? trim((string) $validated['search'])
            : null;

        if ($search === '') {
            $search = null;
        }

        $isActive = null;
        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $isActive = filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return [
            'search' => $search,
            'is_active' => $isActive,
            'sort_by' => $validated['sort_by'] ?? 'created_at',
            'sort_direction' => $validated['sort_direction'] ?? 'desc',
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ];
    }
}

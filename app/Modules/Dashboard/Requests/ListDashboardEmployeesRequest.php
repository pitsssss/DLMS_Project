<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDashboardEmployeesRequest extends FormRequest
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
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'is_active' => ['nullable', 'boolean'],
            // Preserved: existing list intentionally includes admin + employee dashboard accounts.
            'user_type' => ['nullable', 'string', Rule::in(['admin', 'employee'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 25, 50])],
            'sort_by' => ['nullable', 'string', Rule::in(['name', 'email', 'created_at'])],
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
            'role_id' => 'الدور',
            'is_active' => 'حالة التفعيل',
            'user_type' => 'نوع المستخدم',
            'page' => 'رقم الصفحة',
            'per_page' => 'عدد العناصر في الصفحة',
            'sort_by' => 'عمود الترتيب',
            'sort_direction' => 'اتجاه الترتيب',
        ];
    }

    /**
     * Normalized filters for the employee list query.
     *
     * @return array{
     *     search: string|null,
     *     role_id: int|null,
     *     is_active: bool|null,
     *     user_type: string|null,
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

        // Explicit key check so is_active=0 is never discarded by truthy evaluation.
        $isActive = null;
        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $isActive = filter_var($validated['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        return [
            'search' => $search,
            'role_id' => isset($validated['role_id']) ? (int) $validated['role_id'] : null,
            'is_active' => $isActive,
            'user_type' => $validated['user_type'] ?? null,
            'sort_by' => $validated['sort_by'] ?? 'created_at',
            'sort_direction' => $validated['sort_direction'] ?? 'desc',
            'per_page' => (int) ($validated['per_page'] ?? 20),
        ];
    }
}

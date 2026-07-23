<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDashboardLicenseTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge([
                'code' => strtolower(trim((string) $this->input('code'))),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/', 'unique:license_types,code'],
            'minimum_age' => ['required', 'integer', 'min:16', 'max:80'],
            'validity_years' => ['required', 'integer', 'min:1', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'الاسم',
            'code' => 'الرمز',
            'minimum_age' => 'الحد الأدنى للعمر',
            'validity_years' => 'سنوات الصلاحية',
            'is_active' => 'حالة التفعيل',
        ];
    }
}

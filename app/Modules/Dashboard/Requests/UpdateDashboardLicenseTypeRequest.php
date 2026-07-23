<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDashboardLicenseTypeRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'minimum_age' => ['sometimes', 'required', 'integer', 'min:16', 'max:80'],
            'validity_years' => ['sometimes', 'required', 'integer', 'min:1', 'max:30'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            // code is immutable after creation — rejected in service if sent.
            'code' => ['sometimes', 'prohibited'],
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'لا يمكن تعديل رمز نوع الرخصة بعد إنشائه.',
        ];
    }
}

<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDashboardServiceTypeRequest extends FormRequest
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
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
            // code is immutable — used by ServiceCode enum and workflow logic.
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
            'description' => 'الوصف',
            'is_active' => 'حالة التفعيل',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.prohibited' => 'لا يمكن تعديل رمز نوع الخدمة بعد إنشائه.',
        ];
    }
}

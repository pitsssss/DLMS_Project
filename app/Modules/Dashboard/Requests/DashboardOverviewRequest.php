<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DashboardOverviewRequest extends FormRequest
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
            'period' => ['nullable', 'string', Rule::in(['7d', '30d', '90d', '12m'])],
            'recent_limit' => ['nullable', 'integer', 'min:1', 'max:10'],
            'activity_limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'period' => 'الفترة',
            'recent_limit' => 'حد السجلات الحديثة',
            'activity_limit' => 'حد سجلات النشاط',
        ];
    }

    /**
     * @return array{period: string, recent_limit: int, activity_limit: int}
     */
    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'period' => $validated['period'] ?? '30d',
            'recent_limit' => (int) ($validated['recent_limit'] ?? 5),
            'activity_limit' => (int) ($validated['activity_limit'] ?? 8),
        ];
    }
}

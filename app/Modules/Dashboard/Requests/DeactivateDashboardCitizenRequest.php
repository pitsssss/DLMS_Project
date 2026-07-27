<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeactivateDashboardCitizenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge(['reason' => trim(strip_tags((string) $this->input('reason', '')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:1000'],
        ];
    }
}

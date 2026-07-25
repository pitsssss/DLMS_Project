<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectDashboardDocumentRequest extends FormRequest
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
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ];
    }
}

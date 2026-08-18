<?php

namespace App\Modules\Payments\Requests;

use App\Modules\Payments\Services\CitizenPaymentHistoryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CitizenPaymentIndexRequest extends FormRequest
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::in(CitizenPaymentHistoryService::allowedStatuses())],
            'type' => ['sometimes', 'string', Rule::in(CitizenPaymentHistoryService::allowedTypes())],
        ];
    }
}

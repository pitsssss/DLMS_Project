<?php

namespace App\Modules\Fines\Requests;

use App\Enums\FineStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFineRequest extends FormRequest
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
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'reason' => ['sometimes', 'string', 'max:2000'],
            'status' => ['sometimes', 'string', Rule::in([
                FineStatus::Unpaid->value,
                FineStatus::Paid->value,
                FineStatus::Cancelled->value,
            ])],
            // Currency is immutable after creation; clients must not send it.
            'currency' => ['prohibited'],
        ];
    }
}

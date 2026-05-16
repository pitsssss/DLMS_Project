<?php

namespace App\Modules\Tests\Requests;

use App\Enums\TestResultStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordTestResultRequest extends FormRequest
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
            'result' => ['required', 'string', Rule::in([
                TestResultStatus::Passed->value,
                TestResultStatus::Failed->value,
                TestResultStatus::NoShow->value,
            ])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

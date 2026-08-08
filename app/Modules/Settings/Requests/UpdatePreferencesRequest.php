<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
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
            'language' => [
                'nullable',
                'string',
                Rule::in(config('localization.supported', ['ar', 'en'])),
            ],
            'theme' => ['nullable', 'string', 'in:light,dark,system'],
        ];
    }
}

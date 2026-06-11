<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'language' => ['nullable', 'string', 'in:ar,en'],
            'theme' => ['nullable', 'string', 'in:light,dark,system'],
        ];
    }
}

<?php

namespace App\Modules\Applications\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationRequest extends FormRequest
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
            'license_type_id' => [
                'required',
                'integer',
                Rule::exists('license_types', 'id')->where('is_active', true),
            ],
            'service_type_id' => [
                'required',
                'integer',
                Rule::exists('service_types', 'id')->where('is_active', true),
            ],
        ];
    }
}

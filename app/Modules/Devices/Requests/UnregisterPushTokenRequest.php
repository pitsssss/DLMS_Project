<?php

namespace App\Modules\Devices\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnregisterPushTokenRequest extends FormRequest
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
            'device_id' => ['required', 'string', 'min:1', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'device_id.required' => __('messages.devices.validation.device_id_required'),
            'device_id.max' => __('messages.devices.validation.device_id_max'),
        ];
    }
}

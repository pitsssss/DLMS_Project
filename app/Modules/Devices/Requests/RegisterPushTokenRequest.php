<?php

namespace App\Modules\Devices\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterPushTokenRequest extends FormRequest
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
            'platform' => ['required', 'string', 'in:android,ios'],
            'token' => ['required', 'string', 'min:1', 'max:4096'],
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
            'platform.required' => __('messages.devices.validation.platform_required'),
            'platform.in' => __('messages.devices.validation.platform_invalid'),
            'token.required' => __('messages.devices.validation.token_required'),
            'token.max' => __('messages.devices.validation.token_max'),
        ];
    }
}

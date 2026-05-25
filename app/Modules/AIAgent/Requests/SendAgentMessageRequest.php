<?php

namespace App\Modules\AIAgent\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendAgentMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'session_id' => ['sometimes', 'nullable', 'integer', 'exists:ai_agent_sessions,id'],
        ];
    }
}

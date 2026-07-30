<?php

namespace App\Modules\AIAgent\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HandleAgentInteractionRequest extends FormRequest
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
            'action' => [
                'required',
                'string',
                Rule::in([
                    'choose_agent_document_upload',
                    'choose_manual_document_upload',
                    'select_application',
                    'select_required_document',
                    'cancel_document_upload',
                    'show_required_documents',
                    'cancel_pending_workflow',
                    'show_application_choices_again',
                ]),
            ],
            'selection_token' => [
                'nullable',
                'string',
                'max:2048',
                Rule::requiredIf(fn (): bool => in_array(
                    $this->input('action'),
                    ['select_application', 'select_required_document'],
                    true
                )),
            ],
        ];
    }
}

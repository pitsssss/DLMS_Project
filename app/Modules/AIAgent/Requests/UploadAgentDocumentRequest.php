<?php

namespace App\Modules\AIAgent\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadAgentDocumentRequest extends FormRequest
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
            'application_id' => ['required', 'integer', Rule::exists('license_applications', 'id')],
            'required_document_id' => ['required', 'integer', Rule::exists('required_documents', 'id')],
            'file' => ['required', 'file', 'max:5120'],
        ];
    }
}


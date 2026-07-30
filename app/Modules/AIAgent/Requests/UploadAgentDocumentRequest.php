<?php

namespace App\Modules\AIAgent\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UploadAgentDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Token mode is the official Flutter conversational path.
     * Legacy mode keeps application_id + required_document_id for backward compatibility.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hasToken = filled($this->input('upload_token'));

        return [
            'upload_token' => ['nullable', 'string', 'max:255'],
            'application_id' => [
                Rule::requiredIf(! $hasToken),
                'nullable',
                'integer',
                Rule::exists('license_applications', 'id'),
            ],
            'required_document_id' => [
                Rule::requiredIf(! $hasToken),
                'nullable',
                'integer',
                Rule::exists('required_documents', 'id'),
            ],
            // File presence/count is enforced in the controller/service via allFiles() flatten.
            'file' => ['nullable'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $token = $this->input('upload_token');
            $applicationId = $this->input('application_id');
            $requiredDocumentId = $this->input('required_document_id');

            if (filled($token) && (filled($applicationId) || filled($requiredDocumentId))) {
                // Allowed only when IDs are omitted OR will be matched later against token binding.
                // No structural error here; service rejects mismatches.
            }
        });
    }

    public function isTokenMode(): bool
    {
        return filled($this->input('upload_token'));
    }
}

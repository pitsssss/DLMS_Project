<?php

namespace App\Modules\Applications\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicationDocumentRequest extends FormRequest
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
            'required_document_id' => ['required', 'integer', Rule::exists('required_documents', 'id')],
            'file' => ['required', 'file', 'max:5120'],
        ];
    }
}

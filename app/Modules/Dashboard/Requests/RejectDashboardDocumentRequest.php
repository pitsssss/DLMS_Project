<?php

namespace App\Modules\Dashboard\Requests;

use App\Enums\DocumentRejectionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RejectDashboardDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('rejection_reason_code') && is_string($this->input('rejection_reason_code'))) {
            $this->merge([
                'rejection_reason_code' => trim($this->input('rejection_reason_code')),
            ]);
        }

        if ($this->has('rejection_details') && is_string($this->input('rejection_details'))) {
            $trimmed = trim($this->input('rejection_details'));
            $this->merge([
                'rejection_details' => $trimmed === '' ? null : $trimmed,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejection_reason_code' => [
                'required',
                'string',
                Rule::in(DocumentRejectionReason::values()),
            ],
            'rejection_details' => [
                'nullable',
                'string',
                'max:2000',
                Rule::requiredIf(
                    fn (): bool => $this->input('rejection_reason_code') === DocumentRejectionReason::Other->value
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rejection_reason_code' => __('validation.attributes.rejection_reason_code'),
            'rejection_details' => __('validation.attributes.rejection_details'),
        ];
    }

    public function rejectionReason(): DocumentRejectionReason
    {
        return DocumentRejectionReason::from((string) $this->validated('rejection_reason_code'));
    }

    public function rejectionDetails(): ?string
    {
        $details = $this->validated('rejection_details');

        return is_string($details) ? $details : null;
    }
}

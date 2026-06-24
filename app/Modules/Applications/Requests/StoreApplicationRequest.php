<?php

namespace App\Modules\Applications\Requests;

use App\Enums\ServiceCode;
use App\Modules\Applications\Support\ServiceWorkflow;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
                'nullable',
                'integer',
                Rule::exists('license_types', 'id')->where('is_active', true),
            ],
            'license_type_code' => [
                'nullable',
                'string',
                Rule::exists('license_types', 'code')->where('is_active', true),
            ],
            'service_type_id' => [
                'nullable',
                'integer',
                Rule::exists('service_types', 'id')->where('is_active', true),
            ],
            'service_type_code' => [
                'nullable',
                'string',
                Rule::exists('service_types', 'code')->where('is_active', true),
            ],
            'related_license_id' => [
                'nullable',
                'integer',
                Rule::exists('licenses', 'id'),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('service_type_id') && ! $this->filled('service_type_code')) {
                $validator->errors()->add('service_type_code', __('messages.applications.invalid_service_type'));

                return;
            }

            $serviceCode = $this->resolvedServiceCode();

            if ($serviceCode !== null && ServiceWorkflow::requiresRelatedLicense($serviceCode)) {
                if (! $this->filled('related_license_id')) {
                    $validator->errors()->add('related_license_id', __('messages.applications.related_license_required'));
                }

                return;
            }

            if (! $this->filled('license_type_id') && ! $this->filled('license_type_code')) {
                $validator->errors()->add('license_type_id', __('messages.applications.license_type_required'));
            }
        });
    }

    private function resolvedServiceCode(): ?ServiceCode
    {
        if ($this->filled('service_type_code')) {
            return ServiceCode::tryFrom((string) $this->input('service_type_code'));
        }

        if ($this->filled('service_type_id')) {
            $serviceType = \App\Models\ServiceType::query()->find((int) $this->input('service_type_id'));

            return $serviceType ? ServiceCode::tryFrom((string) $serviceType->code) : null;
        }

        return null;
    }
}

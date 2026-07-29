<?php

namespace App\Modules\Dashboard\Requests;

use App\Modules\Dashboard\Requests\Concerns\ValidatesDashboardFeeAmount;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use App\Support\Msg;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDashboardFeeRequest extends FormRequest
{
    use ValidatesDashboardFeeAmount;

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
            'code' => ['required', 'string', Rule::in(ApplicationFeeCatalog::catalogCodes())],
            'name' => ['required', 'string', 'max:255'],
            'amount' => $this->feeAmountRules(true),
            'currency' => ['required', 'string', Rule::in(ApplicationFeeCatalog::allowedCurrencies())],
            'license_type_code' => ['nullable', 'string', 'max:255'],
            'service_type_code' => ['nullable', 'string', 'max:255'],
            'test_type_code' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $code = (string) $this->input('code');
            $definition = ApplicationFeeCatalog::definitionFor($code);
            if ($definition === null) {
                return;
            }

            if ($definition['scope'] === ApplicationFeeCatalog::SCOPE_APPLICATION) {
                if (! $this->filled('license_type_code')) {
                    $validator->errors()->add('license_type_code', Msg::get('fees.validation.license_type_required'));
                }
            }

            if ($definition['scope'] === ApplicationFeeCatalog::SCOPE_SERVICE
                && ! $this->filled('service_type_code')
                && empty($definition['service_code'])) {
                $validator->errors()->add('service_type_code', Msg::get('fees.validation.service_type_required'));
            }

            if ($definition['scope'] === ApplicationFeeCatalog::SCOPE_TEST
                && ! $this->filled('test_type_code')
                && empty($definition['test_code'])) {
                $validator->errors()->add('test_type_code', Msg::get('fees.validation.test_type_required'));
            }
        });
    }
}

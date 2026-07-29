<?php

namespace App\Modules\Dashboard\Requests;

use App\Modules\Dashboard\Requests\Concerns\ValidatesDashboardFeeAmount;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDashboardFeeRequest extends FormRequest
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
            'version' => ['required', 'integer', 'min:0'],
            'code' => ['sometimes', 'string', Rule::in(ApplicationFeeCatalog::catalogCodes())],
            'name' => ['sometimes', 'string', 'max:255'],
            'amount' => $this->feeAmountRules(false),
            'currency' => ['sometimes', 'string', Rule::in(ApplicationFeeCatalog::allowedCurrencies())],
            'license_type_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'service_type_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'test_type_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}

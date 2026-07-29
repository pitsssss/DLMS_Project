<?php

namespace App\Modules\Dashboard\Resources;

use App\Models\Fee;
use App\Modules\Dashboard\Support\DashboardFeeActions;
use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use App\Modules\Payments\Support\Money;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Fee */
class DashboardFeeResource extends JsonResource
{
    /**
     * @var array<string, mixed>
     */
    public static array $detailContext = [];

    public function toArray(Request $request): array
    {
        /** @var Fee $fee */
        $fee = $this->resource;
        $actor = $request->user();
        $usageCount = (int) ($fee->payments_count ?? $fee->payments()->count());
        $definition = ApplicationFeeCatalog::definitionFor((string) $fee->code);
        $scopeType = $definition['scope'] ?? ApplicationFeeCatalog::SCOPE_SERVICE;

        $base = [
            'id' => $fee->id,
            'code' => $fee->code,
            'code_label' => Msg::get('fees.codes.'.$fee->code),
            'name' => $fee->name,
            'amount' => DashboardPaymentPresenter::money($fee->amount),
            'currency' => strtoupper((string) $fee->currency),
            'currency_label' => DashboardPaymentPresenter::currencyLabel((string) $fee->currency),
            'scope_type' => $scopeType,
            'scope_type_label' => Msg::get('fees.scope_types.'.$scopeType),
            'license_type' => $fee->licenseType ? [
                'id' => $fee->licenseType->id,
                'code' => $fee->licenseType->code,
                'name' => EmployeeMessageTranslator::get('employee.license_types.'.$fee->licenseType->code),
            ] : null,
            'service_type' => $fee->serviceType ? [
                'id' => $fee->serviceType->id,
                'code' => $fee->serviceType->code,
                'name' => EmployeeMessageTranslator::get('employee.services.'.$fee->serviceType->code),
            ] : null,
            'test_type' => $fee->testType ? [
                'id' => $fee->testType->id,
                'code' => $fee->testType->code,
                'name' => EmployeeMessageTranslator::get('employee.test_types.'.$fee->testType->code),
            ] : null,
            'is_active' => (bool) $fee->is_active,
            'is_active_label' => Msg::get($fee->is_active ? 'fees.statuses.active' : 'fees.statuses.inactive'),
            'usage' => [
                'payments_count' => $usageCount,
                'is_used' => $usageCount > 0,
            ],
            'created_at' => $fee->created_at?->toIso8601String(),
            'updated_at' => $fee->updated_at?->toIso8601String(),
            'version' => (int) $fee->version,
        ];

        if (! (self::$detailContext['details'] ?? false)) {
            $base['actions'] = $actor ? DashboardFeeActions::for($fee, $actor) : [];

            return $base;
        }

        $immutable = $usageCount > 0;

        $detail = array_merge($base, [
            'identity_key' => $fee->identity_key,
            'immutable_code_scope' => $immutable,
            'pricing_policy_note' => Msg::get('fees.pricing_policy_note'),
            'created_by' => $fee->createdBy ? [
                'id' => $fee->createdBy->id,
                'name' => $fee->createdBy->name,
            ] : null,
            'updated_by' => $fee->updatedBy ? [
                'id' => $fee->updatedBy->id,
                'name' => $fee->updatedBy->name,
            ] : null,
            'deactivated_at' => $fee->deactivated_at?->toIso8601String(),
            'deactivated_by' => $fee->deactivatedBy ? [
                'id' => $fee->deactivatedBy->id,
                'name' => $fee->deactivatedBy->name,
            ] : null,
            'actions' => $actor ? DashboardFeeActions::for($fee, $actor) : [],
        ]);

        self::$detailContext = [];

        return $detail;
    }

    public static function detail(Fee $fee): self
    {
        self::$detailContext = ['details' => true];

        return new self($fee);
    }

    public static function collection($resource)
    {
        self::$detailContext = [];

        return parent::collection($resource);
    }
}

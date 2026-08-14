<?php

namespace App\Modules\Dashboard\Resources;

use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\Dashboard\Support\DashboardLicenseIssuanceActions;
use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Modules\Licenses\Support\LicenseEffectiveStatus;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LicenseApplication */
class DashboardLicenseIssuanceApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LicenseApplication $application */
        $application = $this->resource;
        $actor = $request->user();
        $readiness = is_array($application->issuance_readiness ?? null)
            ? $application->issuance_readiness
            : [
                'is_ready' => false,
                'checklist' => [],
                'blockers' => [],
            ];

        return [
            'id' => $application->id,
            'application_number' => $application->application_number,
            'status' => DashboardPaymentPresenter::applicationStatus($application->status),
            'created_at' => $application->created_at?->toIso8601String(),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'approved_at' => $application->approved_at?->toIso8601String(),
            'citizen' => $application->citizen ? [
                'id' => $application->citizen->id,
                'name' => $application->citizen->name,
            ] : null,
            'service_type' => $application->serviceType ? [
                'id' => $application->serviceType->id,
                'code' => $application->serviceType->code,
                'name' => EmployeeMessageTranslator::get('employee.services.'.$application->serviceType->code),
            ] : null,
            'license_type' => $application->licenseType ? [
                'id' => $application->licenseType->id,
                'code' => $application->licenseType->code,
                'name' => EmployeeMessageTranslator::get('employee.license_types.'.$application->licenseType->code),
            ] : null,
            'related_license' => $this->relatedLicenseSummary($application->relatedLicense),
            'readiness' => [
                'is_ready' => (bool) ($readiness['is_ready'] ?? false),
                'checklist' => $readiness['checklist'] ?? [],
                'blockers' => $readiness['blockers'] ?? [],
            ],
            'actions' => $actor instanceof User
                ? DashboardLicenseIssuanceActions::for($actor, $readiness)
                : [
                    'can_issue_license' => false,
                    'can_view_application' => false,
                ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function relatedLicenseSummary(?License $license): ?array
    {
        if ($license === null) {
            return null;
        }

        $effective = LicenseEffectiveStatus::resolve($license);

        return [
            'id' => $license->id,
            'license_number' => $license->license_number,
            'status' => [
                'value' => $effective->value,
                'label' => Msg::get('licenses.statuses.'.$effective->value),
            ],
            'issue_date' => $license->issue_date?->format('Y-m-d'),
            'expiry_date' => $license->expiry_date?->format('Y-m-d'),
            'license_type' => $license->licenseType ? [
                'id' => $license->licenseType->id,
                'code' => $license->licenseType->code,
                'name' => EmployeeMessageTranslator::get('employee.license_types.'.$license->licenseType->code),
            ] : null,
        ];
    }
}

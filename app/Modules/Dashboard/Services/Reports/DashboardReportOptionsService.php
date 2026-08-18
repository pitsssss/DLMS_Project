<?php

namespace App\Modules\Dashboard\Services\Reports;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\LicenseType;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Dashboard\Support\DashboardPaymentPresenter;
use App\Modules\Dashboard\Support\Reports\ReportVisibility;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;

class DashboardReportOptionsService
{
    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $visibility = ReportVisibility::for($user);

        $periods = [
            ['value' => '7d', 'label' => '7 أيام'],
            ['value' => '30d', 'label' => '30 يوماً'],
            ['value' => '90d', 'label' => '90 يوماً'],
            ['value' => '12m', 'label' => '12 شهراً'],
            ['value' => 'custom', 'label' => 'مخصص'],
        ];

        $grouping = [
            ['value' => 'auto', 'label' => 'تلقائي'],
            ['value' => 'day', 'label' => 'يومي'],
            ['value' => 'week', 'label' => 'أسبوعي'],
            ['value' => 'month', 'label' => 'شهري'],
        ];

        $options = [
            'periods' => $periods,
            'grouping' => $grouping,
            'group_by' => $grouping,
            'visibility' => $visibility,
        ];

        if ($visibility['applications']) {
            $options['application_statuses'] = $this->enumOptions(
                ApplicationStatus::cases(),
                'employee.statuses.'
            );
            $options['service_types'] = $this->catalog(ServiceType::query()->orderBy('name')->get(['code', 'name']));
            $options['license_types'] = $this->catalog(LicenseType::query()->orderBy('name')->get(['code', 'name']));
            $options['test_types'] = $this->catalog(TestType::query()->orderBy('name')->get(['code', 'name']));
        }

        if ($visibility['tests'] || $visibility['appointments']) {
            $options['test_results'] = $this->enumOptions(
                TestResultStatus::cases(),
                'tests.statuses.'
            );
            $options['test_types'] ??= $this->catalog(TestType::query()->orderBy('name')->get(['code', 'name']));
        }

        if ($visibility['appointments']) {
            $options['appointment_statuses'] = $this->enumOptions(
                AppointmentStatus::cases(),
                'appointments.statuses.'
            );
        }

        if ($visibility['document_reviews']) {
            $options['document_statuses'] = $this->enumOptions(
                DocumentStatus::cases(),
                'documents.statuses.'
            );
        }

        if ($visibility['payments']) {
            $options['payment_statuses'] = array_map(
                fn (PaymentStatus $s) => [
                    'value' => $s->value,
                    'label' => Msg::get('payments.statuses.'.$s->value),
                ],
                PaymentStatus::cases()
            );
            $options['currencies'] = [
                ['value' => ApplicationFeeCatalog::CURRENCY, 'label' => DashboardPaymentPresenter::currencyLabel(ApplicationFeeCatalog::CURRENCY)],
            ];
        }

        if ($visibility['fines']) {
            $options['fine_statuses'] = $this->enumOptions(
                FineStatus::cases(),
                'fines.statuses.'
            );
        }

        if ($visibility['employees']) {
            $options['employees'] = User::query()
                ->whereIn('user_type', [UserType::Employee, UserType::Admin])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->name])
                ->values()
                ->all();
            $options['roles'] = Role::query()
                ->orderBy('display_name')
                ->get(['name', 'display_name'])
                ->map(fn (Role $role) => [
                    'value' => $role->name,
                    'label' => $role->display_name ?: $role->name,
                ])
                ->values()
                ->all();
        }

        return $options;
    }

    /**
     * @param  iterable<object>  $rows
     * @return list<array{value: string, label: string}>
     */
    private function catalog(iterable $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = ['value' => (string) $row->code, 'label' => (string) $row->name];
        }

        return $items;
    }

    /**
     * @param  array<int, \BackedEnum>  $cases
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(array $cases, string $labelPrefix): array
    {
        return array_map(
            fn (\BackedEnum $case) => [
                'value' => $case->value,
                'label' => str_contains($labelPrefix, 'employee.')
                    ? EmployeeMessageTranslator::get($labelPrefix.$case->value)
                    : Msg::get($labelPrefix.$case->value),
            ],
            $cases
        );
    }
}

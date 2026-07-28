<?php

namespace App\Modules\Dashboard\Services;

use App\Enums\LicenseStatus;
use App\Enums\ServiceCode;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseStatusHistory;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Dashboard\Support\DashboardLicenseActions;
use App\Modules\Licenses\Support\DigitalLicensePresenter;
use App\Modules\Licenses\Support\LicenseEffectiveStatus;
use App\Support\BusinessClock;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class DashboardIssuedLicenseService
{
    public function __construct(
        private readonly BusinessClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, License>
     */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 20);

        return $this->filteredQuery($filters, $actor)
            ->with([
                'citizen:id,name,national_id',
                'licenseType:id,code,name',
                'application:id,application_number,service_type_id',
                'application.serviceType:id,code,name',
                'issuedBy:id,name',
                'previousLicense:id,license_number,status',
                'replacedBy:id,license_number,status',
            ])
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function stats(array $filters, User $actor): array
    {
        $base = $this->filteredQuery($filters, $actor, applyExpiryFilter: false, applyStatusFilter: false);
        $today = $this->clock->now()->toDateString();
        $soonDays = (int) config('dlms.licenses.expiring_soon_days', 90);
        $soonDate = $this->clock->now()->addDays($soonDays)->toDateString();
        $monthStart = $this->clock->now()->startOfMonth()->toDateString();
        $monthEnd = $this->clock->now()->endOfMonth()->toDateString();

        $effectiveCase = $this->effectiveStatusSql($today);

        $row = (clone $base)
            ->toBase()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN ({$effectiveCase}) = 'active' THEN 1 ELSE 0 END) as active_count")
            ->selectRaw("SUM(CASE WHEN ({$effectiveCase}) = 'expired' THEN 1 ELSE 0 END) as expired_count")
            ->selectRaw("SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_count")
            ->selectRaw("SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_count")
            ->selectRaw("SUM(CASE WHEN status = 'renewed' THEN 1 ELSE 0 END) as renewed_count")
            ->selectRaw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_count")
            ->selectRaw(
                "SUM(CASE WHEN ({$effectiveCase}) = 'active' AND expiry_date >= ? AND expiry_date <= ? THEN 1 ELSE 0 END) as expiring_soon_count",
                [$today, $soonDate]
            )
            ->selectRaw(
                'SUM(CASE WHEN issue_date >= ? AND issue_date <= ? THEN 1 ELSE 0 END) as issued_this_month',
                [$monthStart, $monthEnd]
            )
            ->first();

        $renewedIssued = (clone $base)
            ->whereHas('application.serviceType', fn (Builder $q) => $q->where('code', ServiceCode::RenewLicense->value))
            ->count();

        $replacement = (clone $base)
            ->whereHas('application.serviceType', function (Builder $q): void {
                $q->whereIn('code', [
                    ServiceCode::LostReplacement->value,
                    ServiceCode::DamagedReplacement->value,
                ]);
            })
            ->count();

        return [
            'total' => (int) ($row->total ?? 0),
            'active' => (int) ($row->active_count ?? 0),
            'expired' => (int) ($row->expired_count ?? 0),
            'blocked' => (int) ($row->blocked_count ?? 0),
            'suspended' => (int) ($row->suspended_count ?? 0),
            'renewed' => (int) ($row->renewed_count ?? 0),
            'inactive' => (int) ($row->inactive_count ?? 0),
            'expiring_soon' => (int) ($row->expiring_soon_count ?? 0),
            'issued_this_month' => (int) ($row->issued_this_month ?? 0),
            'renewed_count' => $renewedIssued,
            'replacement_count' => $replacement,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(User $actor): array
    {
        $statuses = array_map(
            fn (LicenseStatus $s) => [
                'value' => $s->value,
                'label' => Msg::get('licenses.statuses.'.$s->value),
            ],
            LicenseStatus::cases()
        );

        $expiryFilters = [
            ['value' => 'all', 'label' => Msg::get('licenses.expiry_filters.all')],
            ['value' => 'active', 'label' => Msg::get('licenses.expiry_filters.active')],
            ['value' => 'expired', 'label' => Msg::get('licenses.expiry_filters.expired')],
            ['value' => 'expiring_soon', 'label' => Msg::get('licenses.expiry_filters.expiring_soon')],
            ['value' => 'expires_within_30_days', 'label' => Msg::get('licenses.expiry_filters.expires_within_30_days')],
            ['value' => 'expires_within_60_days', 'label' => Msg::get('licenses.expiry_filters.expires_within_60_days')],
            ['value' => 'expires_within_90_days', 'label' => Msg::get('licenses.expiry_filters.expires_within_90_days')],
        ];

        $options = [
            'statuses' => $statuses,
            'license_types' => LicenseType::query()->where('is_active', true)->orderBy('name')->get(['code', 'name'])
                ->map(fn (LicenseType $t) => ['value' => $t->code, 'label' => $t->name])
                ->values()
                ->all(),
            'service_types' => ServiceType::query()->where('is_active', true)->orderBy('name')->get(['code', 'name'])
                ->map(fn (ServiceType $t) => ['value' => $t->code, 'label' => $t->name])
                ->values()
                ->all(),
            'expiry_filters' => $expiryFilters,
            'per_page' => [
                ['value' => 10, 'label' => '10'],
                ['value' => 20, 'label' => '20'],
                ['value' => 50, 'label' => '50'],
                ['value' => 100, 'label' => '100'],
            ],
        ];

        if ($actor->hasPermission('manage_employees') || $actor->hasPermission('view_employees') || $actor->isSuperAdmin()) {
            $options['issuing_employees'] = User::query()
                ->whereIn('user_type', [UserType::Employee, UserType::Admin])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])
                ->values()
                ->all();
        }

        return $options;
    }

    public function getLicense(int $licenseId): License
    {
        $license = License::query()
            ->with([
                'citizen:id,name,national_id,phone,email',
                'licenseType:id,code,name',
                'application:id,application_number,service_type_id,status,citizen_id',
                'application.serviceType:id,code,name',
                'issuedBy:id,name',
                'blockedBy:id,name',
                'printedBy:id,name',
                'previousLicense:id,license_number,status,issue_date,expiry_date',
                'replacedBy:id,license_number,status,issue_date,expiry_date',
            ])
            ->find($licenseId);

        if ($license === null) {
            throw new ApiException('messages.licenses.not_found', 404);
        }

        return $license;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(License $license, User $actor): array
    {
        $effective = LicenseEffectiveStatus::resolve($license);
        $canViewApps = $actor->hasPermission('view_applications') || $actor->hasPermission('manage_applications');
        $canViewFines = $actor->hasPermission('view_fines') || $actor->hasPermission('manage_fines');
        $canManageUsers = $actor->hasPermission('manage_users');
        $canAudit = $actor->hasPermission('view_audit_logs');

        $digital = DigitalLicensePresenter::payload($license);

        $payload = [
            'id' => $license->id,
            'license_number' => $license->license_number,
            'status' => $effective->value,
            'status_label' => Msg::get('licenses.statuses.'.$effective->value),
            'stored_status' => $license->status->value,
            'issue_date' => $license->issue_date?->format('Y-m-d'),
            'expiry_date' => $license->expiry_date?->format('Y-m-d'),
            'days_remaining' => LicenseEffectiveStatus::daysRemaining($license),
            'is_expiring_soon' => LicenseEffectiveStatus::isExpiringSoon($license),
            'citizen' => $license->citizen ? [
                'id' => $license->citizen->id,
                'name' => $license->citizen->name,
                'national_id' => $canManageUsers ? $license->citizen->national_id : null,
            ] : null,
            'license_type' => $license->licenseType ? [
                'code' => $license->licenseType->code,
                'label' => $license->licenseType->name,
            ] : null,
            'source_service' => $license->application?->serviceType ? [
                'code' => $license->application->serviceType->code,
                'label' => $license->application->serviceType->name,
            ] : null,
            'application' => $canViewApps && $license->application ? [
                'id' => $license->application->id,
                'application_number' => $license->application->application_number,
                'status' => $license->application->status?->value ?? (string) $license->application->status,
            ] : null,
            'issued_by' => $license->issuedBy ? [
                'id' => $license->issuedBy->id,
                'name' => $license->issuedBy->name,
            ] : [
                'id' => null,
                'name' => Msg::get('licenses.unavailable'),
            ],
            'block' => $license->status === LicenseStatus::Blocked ? [
                'blocked_at' => $license->blocked_at?->toIso8601String(),
                'blocked_by' => $license->blockedBy ? [
                    'id' => $license->blockedBy->id,
                    'name' => $license->blockedBy->name,
                ] : null,
                'reason' => $license->block_reason,
            ] : null,
            'previous_license' => $license->previousLicense ? [
                'id' => $license->previousLicense->id,
                'license_number' => $license->previousLicense->license_number,
                'status' => LicenseEffectiveStatus::value($license->previousLicense),
                'status_label' => LicenseEffectiveStatus::label($license->previousLicense),
            ] : null,
            'replaced_by' => $license->replacedBy ? [
                'id' => $license->replacedBy->id,
                'license_number' => $license->replacedBy->license_number,
                'status' => LicenseEffectiveStatus::value($license->replacedBy),
                'status_label' => LicenseEffectiveStatus::label($license->replacedBy),
            ] : null,
            'lineage' => [
                'has_previous' => $license->previous_license_id !== null,
                'has_successor' => $license->replacedBy !== null,
            ],
            'print' => [
                'print_count' => (int) $license->print_count,
                'printed_at' => $license->printed_at?->toIso8601String(),
                'printed_by' => $license->printedBy ? [
                    'id' => $license->printedBy->id,
                    'name' => $license->printedBy->name,
                ] : null,
            ],
            'digital_license' => $digital,
            'verification' => [
                'url' => $digital['verification_url'],
            ],
            'actions' => DashboardLicenseActions::for($actor, $license),
            'links' => [
                'can_open_citizen' => $canManageUsers,
                'can_open_application' => $canViewApps,
                'can_view_audit_logs' => $canAudit,
            ],
        ];

        if ($canViewFines) {
            $payload['fines_summary'] = [
                'total' => Fine::query()->where('license_id', $license->id)->count(),
                'unpaid' => Fine::query()->where('license_id', $license->id)->where('status', 'unpaid')->count(),
            ];
        }

        return $payload;
    }

    /**
     * @return LengthAwarePaginator<int, LicenseStatusHistory>
     */
    public function paginateHistory(License $license, int $perPage): LengthAwarePaginator
    {
        return LicenseStatusHistory::query()
            ->where('license_id', $license->id)
            ->with('performer:id,name')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginateAuditLogs(License $license, int $perPage): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', 'license')
            ->where('entity_id', $license->id)
            ->with('user:id,name')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function transformListItem(License $license, User $actor): array
    {
        $effective = LicenseEffectiveStatus::resolve($license);
        $canManageUsers = $actor->hasPermission('manage_users');

        return [
            'id' => $license->id,
            'license_number' => $license->license_number,
            'citizen' => $license->citizen ? [
                'id' => $license->citizen->id,
                'name' => $license->citizen->name,
                'national_id' => $canManageUsers ? $license->citizen->national_id : null,
            ] : null,
            'license_type' => $license->licenseType ? [
                'code' => $license->licenseType->code,
                'label' => $license->licenseType->name,
            ] : null,
            'source_service' => $license->application?->serviceType ? [
                'code' => $license->application->serviceType->code,
                'label' => $license->application->serviceType->name,
            ] : null,
            'application' => $license->application ? [
                'id' => $license->application->id,
                'application_number' => $license->application->application_number,
            ] : null,
            'status' => $effective->value,
            'status_label' => Msg::get('licenses.statuses.'.$effective->value),
            'issue_date' => $license->issue_date?->format('Y-m-d'),
            'expiry_date' => $license->expiry_date?->format('Y-m-d'),
            'validity' => [
                'days_remaining' => LicenseEffectiveStatus::daysRemaining($license),
                'is_expiring_soon' => LicenseEffectiveStatus::isExpiringSoon($license),
            ],
            'days_remaining' => LicenseEffectiveStatus::daysRemaining($license),
            'is_expiring_soon' => LicenseEffectiveStatus::isExpiringSoon($license),
            'issued_by' => $license->issuedBy ? [
                'id' => $license->issuedBy->id,
                'name' => $license->issuedBy->name,
            ] : [
                'id' => null,
                'name' => Msg::get('licenses.unavailable'),
            ],
            'lineage' => [
                'has_previous' => $license->previous_license_id !== null,
                'has_successor' => $license->relationLoaded('replacedBy')
                    ? $license->replacedBy !== null
                    : $license->replacedBy()->exists(),
            ],
            'actions' => DashboardLicenseActions::for($actor, $license),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transformHistoryItem(LicenseStatusHistory $row): array
    {
        return [
            'id' => $row->id,
            'action' => $row->action,
            'action_label' => Msg::get('licenses.actions.'.$row->action),
            'from_status' => $row->from_status,
            'from_status_label' => $row->from_status
                ? Msg::get('licenses.statuses.'.$row->from_status)
                : null,
            'to_status' => $row->to_status,
            'to_status_label' => Msg::get('licenses.statuses.'.$row->to_status),
            'reason' => $row->reason,
            'performed_by' => $row->performer ? [
                'id' => $row->performer->id,
                'name' => $row->performer->name,
            ] : null,
            'source' => $row->source,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transformAuditItem(AuditLog $log): array
    {
        $old = is_array($log->old_values) ? $log->old_values : [];
        $new = is_array($log->new_values) ? $log->new_values : [];
        unset($old['verification_token'], $new['verification_token'], $old['token'], $new['token']);

        return [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => $this->auditActionLabel((string) $log->action),
            'performed_by' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
            ] : null,
            'changes' => [
                'old' => $old,
                'new' => $new,
            ],
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(
        array $filters,
        User $actor,
        bool $applyExpiryFilter = true,
        bool $applyStatusFilter = true,
    ): Builder {
        $query = License::query();
        $today = $this->clock->now()->toDateString();

        if ($applyStatusFilter && ! empty($filters['status'])) {
            $status = (string) $filters['status'];
            if ($status === LicenseStatus::Active->value) {
                $query->where('status', LicenseStatus::Active)
                    ->where('expiry_date', '>=', $today);
            } elseif ($status === LicenseStatus::Expired->value) {
                $query->where(function (Builder $q) use ($today): void {
                    $q->where('status', LicenseStatus::Expired)
                        ->orWhere(function (Builder $inner) use ($today): void {
                            $inner->where('status', LicenseStatus::Active)
                                ->where('expiry_date', '<', $today);
                        });
                });
            } else {
                $query->where('status', $status);
            }
        }

        if (! empty($filters['license_type_code'])) {
            $code = (string) $filters['license_type_code'];
            $query->whereHas('licenseType', fn (Builder $q) => $q->where('code', $code));
        }

        if (! empty($filters['service_type_code'])) {
            $code = (string) $filters['service_type_code'];
            $query->whereHas('application.serviceType', fn (Builder $q) => $q->where('code', $code));
        }

        if (! empty($filters['issue_date_from'])) {
            $query->where('issue_date', '>=', $filters['issue_date_from']);
        }
        if (! empty($filters['issue_date_to'])) {
            $query->where('issue_date', '<=', $filters['issue_date_to']);
        }
        if (! empty($filters['expiry_date_from'])) {
            $query->where('expiry_date', '>=', $filters['expiry_date_from']);
        }
        if (! empty($filters['expiry_date_to'])) {
            $query->where('expiry_date', '<=', $filters['expiry_date_to']);
        }

        if (! empty($filters['issued_by'])) {
            $query->where('issued_by', (int) $filters['issued_by']);
        }

        if ($applyExpiryFilter && ! empty($filters['expiry_filter']) && $filters['expiry_filter'] !== 'all') {
            $this->applyExpiryFilter($query, (string) $filters['expiry_filter'], $today);
        }

        if (! empty($filters['search'])) {
            $this->applySearch($query, trim((string) $filters['search']), $actor);
        }

        return $query;
    }

    private function applyExpiryFilter(Builder $query, string $filter, string $today): void
    {
        $days = match ($filter) {
            'expires_within_30_days' => 30,
            'expires_within_60_days' => 60,
            'expiring_soon', 'expires_within_90_days' => (int) config('dlms.licenses.expiring_soon_days', 90),
            default => null,
        };

        if ($filter === 'active') {
            $query->where('status', LicenseStatus::Active)->where('expiry_date', '>=', $today);

            return;
        }

        if ($filter === 'expired') {
            $query->where(function (Builder $q) use ($today): void {
                $q->where('status', LicenseStatus::Expired)
                    ->orWhere(function (Builder $inner) use ($today): void {
                        $inner->where('status', LicenseStatus::Active)
                            ->where('expiry_date', '<', $today);
                    });
            });

            return;
        }

        if ($days !== null) {
            $until = $this->clock->now()->addDays($days)->toDateString();
            $query->where('status', LicenseStatus::Active)
                ->where('expiry_date', '>=', $today)
                ->where('expiry_date', '<=', $until);
        }
    }

    private function applySearch(Builder $query, string $search, User $actor): void
    {
        $like = '%'.$search.'%';
        $canSearchNationalId = $actor->hasPermission('manage_users');

        $query->where(function (Builder $q) use ($like, $search, $canSearchNationalId): void {
            $q->where('license_number', 'like', $like)
                ->orWhereHas('citizen', function (Builder $citizen) use ($like, $search, $canSearchNationalId): void {
                    $citizen->where('name', 'like', $like);
                    if ($canSearchNationalId) {
                        $citizen->orWhere('national_id', 'like', $like)
                            ->orWhere('national_id', $search);
                    }
                })
                ->orWhereHas('application', function (Builder $app) use ($like): void {
                    $app->where('application_number', 'like', $like);
                });
        });
    }

    private function effectiveStatusSql(string $today): string
    {
        $active = LicenseStatus::Active->value;
        $expired = LicenseStatus::Expired->value;

        return "CASE WHEN status = '{$active}' AND expiry_date < '{$today}' THEN '{$expired}' ELSE status END";
    }

    private function auditActionLabel(string $action): string
    {
        return match ($action) {
            'license.issued' => Msg::get('licenses.actions.issued'),
            'license.renewed' => Msg::get('licenses.actions.renewed'),
            'license.replaced', 'license.lost_replacement_issued', 'license.damaged_replacement_issued' => Msg::get('licenses.actions.replaced'),
            'license.blocked' => Msg::get('licenses.actions.blocked'),
            'license.unblocked' => Msg::get('licenses.actions.unblocked'),
            'license.expired' => Msg::get('licenses.actions.expired'),
            'license.printed' => Msg::get('licenses.actions.printed'),
            default => EmployeeMessageTranslator::get('employee.audit.actions.'.str_replace('license.', '', $action)),
        };
    }
}

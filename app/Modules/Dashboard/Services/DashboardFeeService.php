<?php

namespace App\Modules\Dashboard\Services;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Fee;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use App\Modules\Payments\Support\FeeIdentity;
use App\Modules\Payments\Support\Money;
use App\Services\AuditLogService;
use App\Support\EmployeeMessageTranslator;
use App\Support\Msg;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardFeeService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, User $actor): LengthAwarePaginator
    {
        $perPage = min((int) ($filters['per_page'] ?? 20), 100);

        return $this->filteredQuery($filters)
            ->with([
                'licenseType:id,code,name',
                'serviceType:id,code,name',
                'testType:id,code,name',
            ])
            ->withCount('payments')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function stats(array $filters = []): array
    {
        $base = $this->filteredQuery($filters);

        $total = (clone $base)->count();
        $active = (clone $base)->where('is_active', true)->count();
        $inactive = $total - $active;

        $applicationFees = (clone $base)->where('code', 'application_fee')->count();
        $serviceFees = (clone $base)->whereIn('code', [
            'renewal_fee',
            'lost_replacement_fee',
            'damaged_replacement_fee',
            'unblock_fee',
        ])->count();
        $testFees = (clone $base)->whereIn('code', [
            'vision_test_fee',
            'theory_test_fee',
            'practical_test_fee',
        ])->count();

        $usedIds = DB::table('payments')
            ->whereNotNull('fee_id')
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('fee_id');

        $usedByPayments = Fee::query()->whereIn('id', $usedIds)->count();
        $unused = max(0, $total - $usedByPayments);

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'application_fees' => $applicationFees,
            'service_fees' => $serviceFees,
            'test_fees' => $testFees,
            'used_by_payments' => $usedByPayments,
            'unused' => $unused,
            'missing_required_configurations' => $this->missingRequiredConfigurationCount(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        $feeCodes = collect(ApplicationFeeCatalog::catalogCodes())
            ->map(fn (string $code) => [
                'value' => $code,
                'label' => Msg::get('fees.codes.'.$code),
            ])
            ->values()
            ->all();

        $activeStates = [
            ['value' => 'true', 'label' => Msg::get('fees.statuses.active')],
            ['value' => 'false', 'label' => Msg::get('fees.statuses.inactive')],
        ];

        $currencies = collect(ApplicationFeeCatalog::allowedCurrencies())
            ->map(fn (string $code) => [
                'value' => $code,
                'label' => Msg::get('payments.currencies.'.strtolower($code)),
            ])
            ->all();

        $licenseTypes = LicenseType::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (LicenseType $type) => [
                'value' => $type->code,
                'label' => EmployeeMessageTranslator::get('employee.license_types.'.$type->code),
            ])
            ->values()
            ->all();

        $serviceTypes = ServiceType::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (ServiceType $type) => [
                'value' => $type->code,
                'label' => EmployeeMessageTranslator::get('employee.services.'.$type->code),
            ])
            ->values()
            ->all();

        $testTypes = TestType::query()
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (TestType $type) => [
                'value' => $type->code,
                'label' => EmployeeMessageTranslator::get('employee.test_types.'.$type->code),
            ])
            ->values()
            ->all();

        $scopeTypes = [
            ['value' => ApplicationFeeCatalog::SCOPE_APPLICATION, 'label' => Msg::get('fees.scope_types.application')],
            ['value' => ApplicationFeeCatalog::SCOPE_SERVICE, 'label' => Msg::get('fees.scope_types.service')],
            ['value' => ApplicationFeeCatalog::SCOPE_TEST, 'label' => Msg::get('fees.scope_types.test')],
        ];

        return [
            'fee_codes' => $feeCodes,
            'active_states' => $activeStates,
            'currencies' => $currencies,
            'license_types' => $licenseTypes,
            'service_types' => $serviceTypes,
            'test_types' => $testTypes,
            'scope_types' => $scopeTypes,
            'per_page' => [
                ['value' => 10, 'label' => '10'],
                ['value' => 20, 'label' => '20'],
                ['value' => 25, 'label' => '25'],
                ['value' => 50, 'label' => '50'],
            ],
        ];
    }

    public function get(int $feeId): Fee
    {
        $fee = Fee::query()
            ->with([
                'licenseType:id,code,name',
                'serviceType:id,code,name',
                'testType:id,code,name',
                'createdBy:id,name',
                'updatedBy:id,name',
                'deactivatedBy:id,name',
            ])
            ->withCount('payments')
            ->find($feeId);

        if ($fee === null) {
            throw new ApiException('messages.fees.not_found', 404);
        }

        return $fee;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, Request $request): Fee
    {
        $scope = $this->resolveScopeFromInput($data);

        return DB::transaction(function () use ($data, $actor, $request, $scope): Fee {
            $identityKey = FeeIdentity::buildKey(
                (string) $data['code'],
                $scope['license_type_id'],
                $scope['service_type_id'],
                $scope['test_type_id'],
            );

            if (Fee::query()->where('identity_key', $identityKey)->exists()) {
                throw new ApiException('messages.fees.duplicate_identity', 422);
            }

            $fee = Fee::query()->create([
                'code' => $data['code'],
                'identity_key' => $identityKey,
                'name' => trim((string) $data['name']),
                'amount' => Money::format((string) $data['amount']),
                'currency' => strtoupper((string) $data['currency']),
                'license_type_id' => $scope['license_type_id'],
                'service_type_id' => $scope['service_type_id'],
                'test_type_id' => $scope['test_type_id'],
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'version' => 1,
            ]);

            $this->auditLogs->log(
                $actor,
                'fee.created',
                'fee',
                $fee->id,
                null,
                $this->auditValues($fee, $data['reason'] ?? null),
                $request,
            );

            return $fee->fresh([
                'licenseType:id,code,name',
                'serviceType:id,code,name',
                'testType:id,code,name',
                'createdBy:id,name',
                'updatedBy:id,name',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Fee $fee, array $data, User $actor, Request $request): Fee
    {
        return DB::transaction(function () use ($fee, $data, $actor, $request): Fee {
            $locked = Fee::query()
                ->whereKey($fee->id)
                ->with(['licenseType:id,code', 'serviceType:id,code', 'testType:id,code'])
                ->lockForUpdate()
                ->firstOrFail();

            $expectedVersion = (int) $data['version'];
            if ((int) $locked->version !== $expectedVersion) {
                throw new ApiException('messages.fees.stale_version', 409);
            }

            $old = $this->auditSnapshot($locked);
            $used = $locked->hasPaymentUsage();

            if ($used) {
                foreach (['code', 'license_type_code', 'service_type_code', 'test_type_code'] as $immutableField) {
                    if (array_key_exists($immutableField, $data)) {
                        throw new ApiException('messages.fees.immutable_scope_when_used', 422);
                    }
                }
            } else {
                $scopeChanging = array_key_exists('code', $data)
                    || array_key_exists('license_type_code', $data)
                    || array_key_exists('service_type_code', $data)
                    || array_key_exists('test_type_code', $data);

                // Price/name/currency edits should not rebuild identity/scope.
                if ($scopeChanging) {
                    $scope = $this->resolveScopeFromInput(array_merge([
                        'code' => $locked->code,
                        'license_type_code' => $locked->licenseType?->code,
                        'service_type_code' => $locked->serviceType?->code,
                        'test_type_code' => $locked->testType?->code,
                    ], $data));

                    if (array_key_exists('code', $data)) {
                        $locked->code = (string) $data['code'];
                    }

                    $locked->license_type_id = $scope['license_type_id'];
                    $locked->service_type_id = $scope['service_type_id'];
                    $locked->test_type_id = $scope['test_type_id'];

                    $identityKey = FeeIdentity::keyForFee($locked);
                    if (Fee::query()->where('identity_key', $identityKey)->where('id', '!=', $locked->id)->exists()) {
                        throw new ApiException('messages.fees.duplicate_identity', 422);
                    }

                    if (\Illuminate\Support\Facades\Schema::hasColumn('fees', 'identity_key')) {
                        $locked->identity_key = $identityKey;
                    }
                }
            }

            if (array_key_exists('name', $data)) {
                $locked->name = trim((string) $data['name']);
            }
            if (array_key_exists('amount', $data)) {
                $locked->amount = Money::format((string) $data['amount']);
            }
            if (array_key_exists('currency', $data)) {
                $locked->currency = strtoupper((string) $data['currency']);
            }

            $locked->updated_by = $actor->id;
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $fresh = $locked->fresh();
            $this->auditLogs->log(
                $actor,
                'fee.updated',
                'fee',
                $locked->id,
                $old,
                $this->auditValues($fresh, $data['reason'] ?? null),
                $request,
            );

            return $fresh->load([
                'licenseType:id,code,name',
                'serviceType:id,code,name',
                'testType:id,code,name',
                'createdBy:id,name',
                'updatedBy:id,name',
                'deactivatedBy:id,name',
            ]);
        });
    }

    public function activate(Fee $fee, User $actor, Request $request, ?string $reason = null): Fee
    {
        if ($fee->is_active) {
            return $fee;
        }

        return DB::transaction(function () use ($fee, $actor, $request, $reason): Fee {
            $locked = Fee::query()->whereKey($fee->id)->lockForUpdate()->firstOrFail();

            $identityKey = FeeIdentity::keyForFee($locked);
            if (Fee::query()
                ->where('identity_key', $identityKey)
                ->where('id', '!=', $locked->id)
                ->where('is_active', true)
                ->exists()) {
                throw new ApiException('messages.fees.duplicate_active_identity', 422);
            }

            $old = ['is_active' => (bool) $locked->is_active];
            $locked->is_active = true;
            $locked->deactivated_at = null;
            $locked->deactivated_by = null;
            $locked->updated_by = $actor->id;
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $this->auditLogs->log(
                $actor,
                'fee.activated',
                'fee',
                $locked->id,
                $old,
                $this->auditValues($locked, $reason, ['is_active' => true]),
                $request,
            );

            return $locked->fresh([
                'licenseType:id,code,name',
                'serviceType:id,code,name',
                'testType:id,code,name',
            ]);
        });
    }

    public function deactivate(Fee $fee, User $actor, Request $request, ?string $reason = null): Fee
    {
        if (! $fee->is_active) {
            return $fee;
        }

        $this->assertCanDeactivate($fee);

        return DB::transaction(function () use ($fee, $actor, $request, $reason): Fee {
            $locked = Fee::query()->whereKey($fee->id)->lockForUpdate()->firstOrFail();

            $old = ['is_active' => (bool) $locked->is_active];
            $locked->is_active = false;
            $locked->deactivated_at = now();
            $locked->deactivated_by = $actor->id;
            $locked->updated_by = $actor->id;
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            $this->auditLogs->log(
                $actor,
                'fee.deactivated',
                'fee',
                $locked->id,
                $old,
                $this->auditValues($locked, $reason, [
                    'is_active' => false,
                    'deactivated_at' => $locked->deactivated_at?->toIso8601String(),
                ]),
                $request,
            );

            return $locked->fresh([
                'licenseType:id,code,name',
                'serviceType:id,code,name',
                'testType:id,code,name',
                'deactivatedBy:id,name',
            ]);
        });
    }

    public function paginateAuditLogs(Fee $fee, int $perPage): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('entity_type', 'fee')
            ->where('entity_id', $fee->id)
            ->whereIn('action', ['fee.created', 'fee.updated', 'fee.activated', 'fee.deactivated'])
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min($perPage, 100));
    }

    /**
     * @return array<string, mixed>
     */
    public function transformAuditItem(AuditLog $log): array
    {
        $old = is_array($log->old_values) ? $log->old_values : [];
        $new = is_array($log->new_values) ? $log->new_values : [];

        return [
            'id' => $log->id,
            'action' => $log->action,
            'action_label' => Msg::get('fees.audit_actions.'.$log->action),
            'performed_by' => $log->user ? [
                'id' => $log->user->id,
                'name' => $log->user->name,
            ] : null,
            'reason' => $new['reason'] ?? $old['reason'] ?? null,
            'changes' => [
                'old' => $old,
                'new' => $new,
            ],
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    public function assertCanDeactivate(Fee $fee): void
    {
        if (in_array($fee->code, ['vision_test_fee', 'theory_test_fee', 'practical_test_fee'], true)) {
            return;
        }

        if ($fee->code === 'application_fee') {
            $licenseTypeId = $fee->license_type_id;
            if ($licenseTypeId === null) {
                return;
            }

            $others = Fee::query()
                ->where('code', 'application_fee')
                ->where('license_type_id', $licenseTypeId)
                ->where('is_active', true)
                ->where('id', '!=', $fee->id)
                ->count();

            if ($others === 0) {
                throw new ApiException('messages.fees.unsafe_deactivation', 422);
            }

            return;
        }

        if (ApplicationFeeCatalog::isApplicationPayable((string) $fee->code)) {
            $serviceTypeId = $fee->service_type_id;
            if ($serviceTypeId === null) {
                return;
            }

            $others = Fee::query()
                ->where('code', $fee->code)
                ->where('service_type_id', $serviceTypeId)
                ->whereNull('license_type_id')
                ->where('is_active', true)
                ->where('id', '!=', $fee->id)
                ->count();

            if ($others === 0) {
                throw new ApiException('messages.fees.unsafe_deactivation', 422);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{license_type_id: ?int, service_type_id: ?int, test_type_id: ?int}
     */
    public function resolveScopeFromInput(array $data): array
    {
        $code = trim((string) ($data['code'] ?? ''));
        $definition = ApplicationFeeCatalog::definitionFor($code);
        if ($definition === null) {
            throw new ApiException('messages.fees.unsupported_code', 422);
        }

        $licenseTypeId = null;
        $serviceTypeId = null;
        $testTypeId = null;

        if ($definition['scope'] === ApplicationFeeCatalog::SCOPE_APPLICATION) {
            $licenseType = $this->requireLicenseType($data['license_type_code'] ?? null);
            $serviceType = $this->requireServiceType($definition['service_code'] ?? null);
            $licenseTypeId = $licenseType->id;
            $serviceTypeId = $serviceType->id;
        } elseif ($definition['scope'] === ApplicationFeeCatalog::SCOPE_SERVICE) {
            $serviceCode = $data['service_type_code'] ?? ($definition['service_code'] ?? null);
            $serviceType = $this->requireServiceType($serviceCode);
            $serviceTypeId = $serviceType->id;
        } else {
            $testCode = $data['test_type_code'] ?? ($definition['test_code'] ?? null);
            $testType = $this->requireTestType($testCode);
            $testTypeId = $testType->id;
        }

        return FeeIdentity::normalizedScope($licenseTypeId, $serviceTypeId, $testTypeId);
    }

    private function requireLicenseType(?string $code): LicenseType
    {
        if ($code === null || trim($code) === '') {
            throw new ApiException('messages.fees.license_type_required', 422);
        }

        $type = LicenseType::query()->where('code', trim($code))->first();
        if ($type === null) {
            throw new ApiException('messages.fees.license_type_invalid', 422);
        }

        return $type;
    }

    private function requireServiceType(?string $code): ServiceType
    {
        if ($code === null || trim($code) === '') {
            throw new ApiException('messages.fees.service_type_required', 422);
        }

        $type = ServiceType::query()->where('code', trim($code))->first();
        if ($type === null) {
            throw new ApiException('messages.fees.service_type_invalid', 422);
        }

        return $type;
    }

    private function requireTestType(?string $code): TestType
    {
        if ($code === null || trim($code) === '') {
            throw new ApiException('messages.fees.test_type_required', 422);
        }

        $type = TestType::query()->where('code', trim($code))->first();
        if ($type === null) {
            throw new ApiException('messages.fees.test_type_invalid', 422);
        }

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(Fee $fee): array
    {
        return [
            'code' => $fee->code,
            'name' => $fee->name,
            'amount' => Money::format((string) $fee->amount),
            'currency' => strtoupper((string) $fee->currency),
            'license_type_id' => $fee->license_type_id,
            'service_type_id' => $fee->service_type_id,
            'test_type_id' => $fee->test_type_id,
            'is_active' => (bool) $fee->is_active,
            'version' => (int) $fee->version,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function auditValues(Fee $fee, ?string $reason = null, array $extra = []): array
    {
        $values = array_merge($this->auditSnapshot($fee), $extra);

        if ($reason !== null && trim($reason) !== '') {
            $values['reason'] = trim($reason);
        }

        return $values;
    }

    private function missingRequiredConfigurationCount(): int
    {
        $missing = 0;
        $newLicense = ServiceType::query()->where('code', 'new_license')->value('id');

        if ($newLicense !== null) {
            foreach (LicenseType::query()->where('is_active', true)->pluck('id') as $licenseTypeId) {
                $exists = Fee::query()
                    ->where('code', 'application_fee')
                    ->where('license_type_id', $licenseTypeId)
                    ->where('service_type_id', $newLicense)
                    ->where('is_active', true)
                    ->exists();

                if (! $exists) {
                    $missing++;
                }
            }
        }

        foreach (ApplicationFeeCatalog::payableCodes() as $code) {
            if ($code === 'application_fee') {
                continue;
            }

            $definition = ApplicationFeeCatalog::definitionFor($code);
            $serviceCode = $definition['service_code'] ?? null;
            if ($serviceCode === null) {
                continue;
            }

            $serviceTypeId = ServiceType::query()->where('code', $serviceCode)->value('id');
            if ($serviceTypeId === null) {
                continue;
            }

            $exists = Fee::query()
                ->where('code', $code)
                ->where('service_type_id', $serviceTypeId)
                ->whereNull('license_type_id')
                ->where('is_active', true)
                ->exists();

            if (! $exists) {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Fee>
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = Fee::query();

        if (! empty($filters['search'])) {
            $like = '%'.trim((string) $filters['search']).'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            });
        }

        if (! empty($filters['code'])) {
            $query->where('code', (string) $filters['code']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['currency'])) {
            $query->where('currency', strtoupper((string) $filters['currency']));
        }

        if (! empty($filters['license_type_code'])) {
            $code = (string) $filters['license_type_code'];
            $query->whereHas('licenseType', fn (Builder $q) => $q->where('code', $code));
        }

        if (! empty($filters['service_type_code'])) {
            $code = (string) $filters['service_type_code'];
            $query->whereHas('serviceType', fn (Builder $q) => $q->where('code', $code));
        }

        if (! empty($filters['test_type_code'])) {
            $code = (string) $filters['test_type_code'];
            $query->whereHas('testType', fn (Builder $q) => $q->where('code', $code));
        }

        return $query;
    }
}

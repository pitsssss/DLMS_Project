<?php

namespace App\Modules\Dashboard\Services;

use App\Exceptions\ApiException;
use App\Models\LicenseApplication;
use App\Modules\Licenses\Services\LicenseIssuanceEligibilityService;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardLicenseIssuanceService
{
    public function __construct(
        private readonly LicenseIssuanceEligibilityService $eligibility,
        private readonly BusinessClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, LicenseApplication>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = $this->eligibility->eligibleQuery()
            ->with($this->defaultRelations())
            ->orderByRaw('COALESCE(license_applications.approved_at, license_applications.created_at) ASC')
            ->orderBy('license_applications.id');

        $this->applyServiceTypeFilter($query, $filters);
        $this->applyLicenseTypeFilter($query, $filters);
        $this->applyDateFilters($query, $filters);
        $this->applySearchFilter($query, $filters['search'] ?? null);

        $paginator = $query->paginate($perPage);
        $this->attachReadiness(collect($paginator->items()));

        return $paginator;
    }

    public function getById(int $applicationId): LicenseApplication
    {
        $application = LicenseApplication::query()
            ->with($this->defaultRelations())
            ->whereKey($applicationId)
            ->first();

        if ($application === null) {
            throw new ApiException('messages.applications.not_found', 404);
        }

        $this->attachReadiness(collect([$application]));

        return $application;
    }

    /**
     * @return array<int, mixed>
     */
    private function defaultRelations(): array
    {
        return [
            'citizen:id,name',
            'serviceType:id,code,name',
            'licenseType:id,code,name',
            'relatedLicense:id,license_number,status,issue_date,expiry_date,license_type_id',
            'relatedLicense.licenseType:id,code,name',
            'license:id,application_id',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyServiceTypeFilter(Builder $query, array $filters): void
    {
        if (! empty($filters['service_type_id'])) {
            $query->where('license_applications.service_type_id', (int) $filters['service_type_id']);
        }

        if (! empty($filters['service_type_code'])) {
            $code = (string) $filters['service_type_code'];
            $query->whereHas('serviceType', fn (Builder $q) => $q->where('code', $code));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyLicenseTypeFilter(Builder $query, array $filters): void
    {
        if (! empty($filters['license_type_id'])) {
            $query->where('license_applications.license_type_id', (int) $filters['license_type_id']);
        }

        if (! empty($filters['license_type_code'])) {
            $code = (string) $filters['license_type_code'];
            $query->whereHas('licenseType', fn (Builder $q) => $q->where('code', $code));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        $tz = $this->clock->timezone();
        $column = 'license_applications.approved_at';

        if (! empty($filters['date'])) {
            $day = CarbonImmutable::parse((string) $filters['date'], $tz)->startOfDay();
            $this->clock->applyUtcRange(
                $query,
                $column,
                $this->clock->toUtc($day),
                $this->clock->toUtc($day->addDay())
            );

            return;
        }

        if (! empty($filters['date_from'])) {
            $from = CarbonImmutable::parse((string) $filters['date_from'], $tz)->startOfDay();
            $query->where($column, '>=', $this->clock->toUtc($from));
        }

        if (! empty($filters['date_to'])) {
            $toExclusive = CarbonImmutable::parse((string) $filters['date_to'], $tz)->startOfDay()->addDay();
            $query->where($column, '<', $this->clock->toUtc($toExclusive));
        }
    }

    private function applySearchFilter(Builder $query, mixed $search): void
    {
        if (! is_string($search) || $search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function (Builder $inner) use ($like): void {
            $inner->where('license_applications.application_number', 'like', $like)
                ->orWhereHas('citizen', fn (Builder $q) => $q->where('name', 'like', $like));
        });
    }

    /**
     * @param  Collection<int, LicenseApplication>  $applications
     */
    private function attachReadiness(Collection $applications): void
    {
        foreach ($applications as $application) {
            $application->setAttribute('issuance_readiness', $this->eligibility->evaluate($application));
        }
    }
}

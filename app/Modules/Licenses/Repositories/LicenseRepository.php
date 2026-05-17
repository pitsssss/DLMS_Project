<?php

namespace App\Modules\Licenses\Repositories;

use App\Models\License;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LicenseRepository
{
    /**
     * @return Collection<int, License>
     */
    public function listForCitizen(User $citizen): Collection
    {
        return License::query()
            ->where('citizen_id', $citizen->id)
            ->with(['licenseType', 'application'])
            ->orderByDesc('id')
            ->get();
    }

    public function findOwnedByCitizen(User $citizen, int $licenseId): ?License
    {
        return License::query()
            ->whereKey($licenseId)
            ->where('citizen_id', $citizen->id)
            ->with(['licenseType', 'application'])
            ->first();
    }

    public function findById(int $licenseId): ?License
    {
        return License::query()
            ->whereKey($licenseId)
            ->with(['licenseType', 'application', 'citizen'])
            ->first();
    }

    public function existsForApplication(int $applicationId): bool
    {
        return License::query()->where('application_id', $applicationId)->exists();
    }

    public function generateUniqueLicenseNumber(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $number = 'LIC-'.now()->format('Y').'-'.strtoupper(Str::random(10));
            if (! License::query()->where('license_number', $number)->exists()) {
                return $number;
            }
        }

        return 'LIC-'.now()->format('Y').'-'.strtoupper(Str::uuid()->toString());
    }

    public function create(array $attributes): License
    {
        return License::query()->create($attributes);
    }
}

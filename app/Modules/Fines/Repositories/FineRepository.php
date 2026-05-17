<?php

namespace App\Modules\Fines\Repositories;

use App\Models\Fine;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class FineRepository
{
    /**
     * @return LengthAwarePaginator<int, Fine>
     */
    public function paginateForAdmin(int $perPage, ?string $status = null, ?int $citizenId = null): LengthAwarePaginator
    {
        $query = Fine::query()
            ->with(['citizen', 'license'])
            ->orderByDesc('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        if ($citizenId !== null) {
            $query->where('citizen_id', $citizenId);
        }

        return $query->paginate($perPage);
    }

    /**
     * @return Collection<int, Fine>
     */
    public function listForCitizen(User $citizen): Collection
    {
        return Fine::query()
            ->where('citizen_id', $citizen->id)
            ->with('license')
            ->orderByDesc('id')
            ->get();
    }

    public function findById(int $fineId): ?Fine
    {
        return Fine::query()
            ->whereKey($fineId)
            ->with(['citizen', 'license'])
            ->first();
    }
}

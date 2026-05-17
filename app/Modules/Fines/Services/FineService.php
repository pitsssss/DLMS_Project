<?php

namespace App\Modules\Fines\Services;

use App\Enums\FineStatus;
use App\Exceptions\ApiException;
use App\Models\Fine;
use App\Models\License;
use App\Models\User;
use App\Modules\Fines\Repositories\FineRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FineService
{
    public function __construct(
        private readonly FineRepository $fines
    ) {}

    /**
     * @return LengthAwarePaginator<int, Fine>
     */
    public function paginateForAdmin(int $perPage, ?string $status = null, ?int $citizenId = null): LengthAwarePaginator
    {
        return $this->fines->paginateForAdmin($perPage, $status, $citizenId);
    }

    /**
     * @return Collection<int, Fine>
     */
    public function listForCitizen(User $citizen): Collection
    {
        return $this->fines->listForCitizen($citizen);
    }

    public function create(User $actor, int $citizenId, float $amount, string $reason, ?int $licenseId = null): Fine
    {
        if ($amount <= 0) {
            throw new ApiException('Fine amount must be greater than zero.', 422);
        }

        $citizen = User::query()->whereKey($citizenId)->first();
        if ($citizen === null || ! $citizen->isCitizen()) {
            throw new ApiException('Citizen not found.', 404);
        }

        if ($licenseId !== null) {
            $license = License::query()->whereKey($licenseId)->where('citizen_id', $citizenId)->first();
            if ($license === null) {
                throw new ApiException('License not found for this citizen.', 404);
            }
        }

        return Fine::query()->create([
            'citizen_id' => $citizenId,
            'license_id' => $licenseId,
            'amount' => $amount,
            'reason' => $reason,
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);
    }

    public function update(User $actor, int $fineId, array $data): Fine
    {
        return DB::transaction(function () use ($fineId, $data) {
            $fine = Fine::query()->whereKey($fineId)->lockForUpdate()->first();

            if ($fine === null) {
                throw new ApiException('Fine not found.', 404);
            }

            if (isset($data['amount'])) {
                if ((float) $data['amount'] <= 0) {
                    throw new ApiException('Fine amount must be greater than zero.', 422);
                }
                $fine->amount = $data['amount'];
            }

            if (isset($data['reason']) && is_string($data['reason'])) {
                $fine->reason = $data['reason'];
            }

            if (isset($data['status'])) {
                $status = FineStatus::from($data['status']);

                if ($status === FineStatus::Paid) {
                    $fine->status = FineStatus::Paid;
                    $fine->paid_at = now();
                } elseif ($status === FineStatus::Cancelled) {
                    if ($fine->status === FineStatus::Paid) {
                        throw new ApiException('Paid fines cannot be cancelled.', 422);
                    }
                    $fine->status = FineStatus::Cancelled;
                } elseif ($status === FineStatus::Unpaid && $fine->status !== FineStatus::Paid) {
                    $fine->status = FineStatus::Unpaid;
                    $fine->paid_at = null;
                }
            }

            $fine->save();

            return $fine->fresh(['citizen', 'license']);
        });
    }
}

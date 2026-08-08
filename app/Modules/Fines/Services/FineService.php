<?php

namespace App\Modules\Fines\Services;

use App\Enums\FineStatus;
use App\Exceptions\ApiException;
use App\Models\Fine;
use App\Models\License;
use App\Models\User;
use App\Modules\Fines\Repositories\FineRepository;
use App\Modules\Notifications\Services\NotificationService;
use App\Services\AuditLogService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FineService
{
    public function __construct(
        private readonly FineRepository $fines,
        private readonly AuditLogService $auditLogs,
        private readonly NotificationService $notifications
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
            throw new ApiException('messages.fines.amount_invalid', 422);
        }

        $citizen = User::query()->whereKey($citizenId)->first();
        if ($citizen === null || ! $citizen->isCitizen()) {
            throw new ApiException('messages.fines.citizen_not_found', 404);
        }

        if ($licenseId !== null) {
            $license = License::query()->whereKey($licenseId)->where('citizen_id', $citizenId)->first();
            if ($license === null) {
                throw new ApiException('messages.fines.license_not_found', 404);
            }
        }

        $fine = Fine::query()->create([
            'citizen_id' => $citizenId,
            'license_id' => $licenseId,
            'amount' => $amount,
            'reason' => $reason,
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);

        $this->auditLogs->log(
            $actor,
            'fine.created',
            'fine',
            $fine->id,
            null,
            ['amount' => $amount, 'citizen_id' => $citizenId, 'status' => FineStatus::Unpaid->value]
        );

        $this->notifications->sendLocalizedToUser(
            $citizenId,
            'messages.notifications.fine_issued_title',
            'messages.notifications.fine_issued_body',
            ['amount' => $amount, 'reason' => $reason],
            'fine.created',
            ['fine_id' => $fine->id]
        );

        return $fine;
    }

    public function update(User $actor, int $fineId, array $data): Fine
    {
        return DB::transaction(function () use ($fineId, $data, $actor) {
            $fine = Fine::query()->whereKey($fineId)->lockForUpdate()->first();

            if ($fine === null) {
                throw new ApiException('messages.fines.not_found', 404);
            }

            if (isset($data['amount'])) {
                if ((float) $data['amount'] <= 0) {
                    throw new ApiException('messages.fines.amount_invalid', 422);
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
                        throw new ApiException('messages.fines.paid_cannot_cancel', 422);
                    }
                    $fine->status = FineStatus::Cancelled;
                } elseif ($status === FineStatus::Unpaid && $fine->status !== FineStatus::Paid) {
                    $fine->status = FineStatus::Unpaid;
                    $fine->paid_at = null;
                }
            }

            $fine->save();

            if (isset($data['status']) && FineStatus::from($data['status']) === FineStatus::Paid) {
                $this->notifications->sendLocalizedToUser(
                    $fine->citizen_id,
                    'messages.notifications.fine_paid_title',
                    'messages.notifications.fine_paid_body',
                    [],
                    'fine.paid',
                    ['fine_id' => $fine->id]
                );
            }

            $this->auditLogs->log(
                $actor,
                'fine.updated',
                'fine',
                $fine->id,
                null,
                ['status' => $fine->status->value]
            );

            return $fine->fresh(['citizen', 'license']);
        });
    }
}

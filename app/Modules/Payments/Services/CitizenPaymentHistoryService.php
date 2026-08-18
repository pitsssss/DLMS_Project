<?php

namespace App\Modules\Payments\Services;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CitizenPaymentHistoryService
{
    /**
     * @param  array{status?: string, type?: string, per_page?: int, page?: int}  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    public function paginateForCitizen(User $citizen, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 15);

        $query = Payment::query()
            ->where('user_id', $citizen->id)
            ->where(function ($q): void {
                $q->where(function ($application): void {
                    $application->whereNotNull('application_id')->whereNull('fine_id');
                })->orWhere(function ($fine): void {
                    $fine->whereNotNull('fine_id')->whereNull('application_id');
                });
            })
            ->with([
                'fee',
                'fine',
                'application.serviceType',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (($filters['type'] ?? null) === 'fine') {
            $query->whereNotNull('fine_id')->whereNull('application_id');
        } elseif (($filters['type'] ?? null) === 'application') {
            $query->whereNotNull('application_id')->whereNull('fine_id');
        }

        return $query->paginate($perPage);
    }

    public function findOwnedByCitizen(User $citizen, int $paymentId): Payment
    {
        $payment = Payment::query()
            ->whereKey($paymentId)
            ->where('user_id', $citizen->id)
            ->where(function ($q): void {
                $q->where(function ($application): void {
                    $application->whereNotNull('application_id')->whereNull('fine_id');
                })->orWhere(function ($fine): void {
                    $fine->whereNotNull('fine_id')->whereNull('application_id');
                });
            })
            ->with([
                'fee',
                'fine.license',
                'application.serviceType',
                'application.licenseType',
            ])
            ->first();

        if ($payment === null) {
            throw new ApiException('messages.payments.not_found', 404);
        }

        return $payment;
    }

    /**
     * @return list<string>
     */
    public static function allowedStatuses(): array
    {
        return array_map(
            static fn (PaymentStatus $status): string => $status->value,
            PaymentStatus::cases()
        );
    }

    /**
     * @return list<string>
     */
    public static function allowedTypes(): array
    {
        return ['application', 'fine'];
    }
}

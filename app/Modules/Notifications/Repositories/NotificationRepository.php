<?php

namespace App\Modules\Notifications\Repositories;

use App\Exceptions\ApiException;
use App\Models\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class NotificationRepository
{
    /**
     * @return LengthAwarePaginator<int, Notification>
     */
    public function paginateForUser(int $userId, int $perPage, ?bool $unreadOnly = null): LengthAwarePaginator
    {
        $query = Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('id');

        if ($unreadOnly === true) {
            $query->whereNull('read_at');
        }

        return $query->paginate($perPage);
    }

    /**
     * @return Collection<int, Notification>
     */
    public function listForUser(int $userId): Collection
    {
        return Notification::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get();
    }

    public function markAsRead(int $userId, int $notificationId): Notification
    {
        $notification = Notification::query()
            ->whereKey($notificationId)
            ->where('user_id', $userId)
            ->first();

        if ($notification === null) {
            throw new ApiException('Notification not found.', 404);
        }

        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        return $notification;
    }
}

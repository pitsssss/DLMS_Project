<?php

namespace App\Modules\Notifications\Services;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Notifications\Repositories\NotificationRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications
    ) {}

    public function sendToUser(
        int $userId,
        string $title,
        string $body,
        ?string $type = null,
        ?array $data = null
    ): Notification {
        return Notification::query()->create([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'type' => $type,
            'read_at' => null,
            'data' => $data,
        ]);
    }

    public function notifyApplicationStatusChange(LicenseApplication $application, ApplicationStatus $newStatus): void
    {
        $messages = [
            ApplicationStatus::PaymentPending->value => [
                'title' => __('messages.notifications.payment_required_title'),
                'body' => __('messages.notifications.payment_required_body'),
                'type' => 'application.payment_pending',
            ],
            ApplicationStatus::DocumentsRejected->value => [
                'title' => __('messages.notifications.documents_rejected_title'),
                'body' => __('messages.notifications.documents_rejected_body'),
                'type' => 'application.documents_rejected',
            ],
            ApplicationStatus::AppointmentPending->value => [
                'title' => __('messages.notifications.appointment_pending_title'),
                'body' => __('messages.notifications.appointment_pending_body'),
                'type' => 'application.appointment_pending',
            ],
            ApplicationStatus::Approved->value => [
                'title' => __('messages.notifications.approved_title'),
                'body' => __('messages.notifications.approved_body'),
                'type' => 'application.approved',
            ],
            ApplicationStatus::LicenseIssued->value => [
                'title' => __('messages.notifications.license_issued_title'),
                'body' => __('messages.notifications.license_issued_body'),
                'type' => 'application.license_issued',
            ],
            ApplicationStatus::WaitingRetest->value => [
                'title' => __('messages.notifications.retest_title'),
                'body' => __('messages.notifications.retest_body'),
                'type' => 'application.waiting_retest',
            ],
            ApplicationStatus::AdministrativeReview->value => [
                'title' => __('messages.notifications.admin_review_title'),
                'body' => __('messages.notifications.admin_review_body'),
                'type' => 'application.administrative_review',
            ],
        ];

        $message = $messages[$newStatus->value] ?? null;
        if ($message === null) {
            return;
        }

        $this->sendToUser(
            $application->citizen_id,
            $message['title'],
            $message['body'],
            $message['type'],
            [
                'application_id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $newStatus->value,
            ]
        );
    }

    /**
     * @return LengthAwarePaginator<int, Notification>
     */
    public function paginateForUser(User $user, int $perPage, ?bool $unreadOnly = null): LengthAwarePaginator
    {
        return $this->notifications->paginateForUser($user->id, $perPage, $unreadOnly);
    }

    /**
     * @return Collection<int, Notification>
     */
    public function listForUser(User $user): Collection
    {
        return $this->notifications->listForUser($user->id);
    }

    public function markAsRead(User $user, int $notificationId): Notification
    {
        return $this->notifications->markAsRead($user->id, $notificationId);
    }
}

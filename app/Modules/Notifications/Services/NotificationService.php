<?php

namespace App\Modules\Notifications\Services;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Notifications\Repositories\NotificationRepository;
use App\Support\RecipientNotificationTranslator;
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

    /**
     * Create a notification using the recipient's stored language preference.
     * Does not mutate application/request locale.
     *
     * @param  array<string, mixed>  $replace
     * @param  array<string, mixed>|null  $data
     */
    public function sendLocalizedToUser(
        int $userId,
        string $titleKey,
        string $bodyKey,
        array $replace = [],
        ?string $type = null,
        ?array $data = null
    ): Notification {
        $locale = RecipientNotificationTranslator::localeForUserId($userId);

        return $this->sendToUser(
            $userId,
            RecipientNotificationTranslator::get($titleKey, $replace, $locale),
            RecipientNotificationTranslator::get($bodyKey, $replace, $locale),
            $type,
            $data
        );
    }

    public function notifyApplicationStatusChange(LicenseApplication $application, ApplicationStatus $newStatus): void
    {
        $messages = [
            ApplicationStatus::PaymentPending->value => [
                'title' => 'messages.notifications.payment_required_title',
                'body' => 'messages.notifications.payment_required_body',
                'type' => 'application.payment_pending',
            ],
            ApplicationStatus::DocumentsRejected->value => [
                'title' => 'messages.notifications.documents_rejected_title',
                'body' => 'messages.notifications.documents_rejected_body',
                'type' => 'application.documents_rejected',
            ],
            ApplicationStatus::DocumentsUnderReview->value => [
                'title' => 'messages.notifications.documents_under_review_title',
                'body' => 'messages.notifications.documents_under_review_body',
                'type' => 'application.documents_under_review',
            ],
            ApplicationStatus::AppointmentPending->value => [
                'title' => 'messages.notifications.appointment_pending_title',
                'body' => 'messages.notifications.appointment_pending_body',
                'type' => 'application.appointment_pending',
            ],
            ApplicationStatus::Approved->value => [
                'title' => 'messages.notifications.approved_title',
                'body' => 'messages.notifications.approved_body',
                'type' => 'application.approved',
            ],
            ApplicationStatus::LicenseIssued->value => [
                'title' => 'messages.notifications.license_issued_title',
                'body' => 'messages.notifications.license_issued_body',
                'type' => 'application.license_issued',
            ],
            ApplicationStatus::WaitingRetest->value => [
                'title' => 'messages.notifications.retest_title',
                'body' => 'messages.notifications.retest_body',
                'type' => 'application.waiting_retest',
            ],
            ApplicationStatus::AdministrativeReview->value => [
                'title' => 'messages.notifications.admin_review_title',
                'body' => 'messages.notifications.admin_review_body',
                'type' => 'application.administrative_review',
            ],
        ];

        $message = $messages[$newStatus->value] ?? null;
        if ($message === null) {
            return;
        }

        $this->sendLocalizedToUser(
            $application->citizen_id,
            $message['title'],
            $message['body'],
            [],
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

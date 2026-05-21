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
                'title' => 'Payment required',
                'body' => 'Your documents were approved. Please pay the application fee to continue.',
                'type' => 'application.payment_pending',
            ],
            ApplicationStatus::DocumentsRejected->value => [
                'title' => 'Documents rejected',
                'body' => 'One or more documents were rejected. Please review and resubmit.',
                'type' => 'application.documents_rejected',
            ],
            ApplicationStatus::AppointmentPending->value => [
                'title' => 'Book your test appointment',
                'body' => 'Payment received. You can now book your driving test appointments.',
                'type' => 'application.appointment_pending',
            ],
            ApplicationStatus::Approved->value => [
                'title' => 'Application approved',
                'body' => 'Congratulations! All required tests passed. Your application is approved.',
                'type' => 'application.approved',
            ],
            ApplicationStatus::LicenseIssued->value => [
                'title' => 'License issued',
                'body' => 'Your driving license has been issued. You can view it in the app.',
                'type' => 'application.license_issued',
            ],
            ApplicationStatus::WaitingRetest->value => [
                'title' => 'Retest required',
                'body' => 'A test was not passed. Please book a retest appointment.',
                'type' => 'application.waiting_retest',
            ],
            ApplicationStatus::AdministrativeReview->value => [
                'title' => 'Administrative review',
                'body' => 'Your application requires administrative review. You will be contacted.',
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

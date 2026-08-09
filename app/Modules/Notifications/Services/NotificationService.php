<?php

namespace App\Modules\Notifications\Services;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Models\LicenseApplication;
use App\Models\Notification;
use App\Models\User;
use App\Modules\Notifications\Repositories\NotificationRepository;
use App\Modules\Notifications\Support\NotificationEventKey;
use App\Modules\Notifications\Support\NotificationPayload;
use App\Support\RecipientNotificationTranslator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canonical citizen in-app notification creation layer.
 *
 * Strategy:
 * - Persist only after the outermost DB transaction commits (DB::afterCommit).
 * - In PHPUnit, callbacks run immediately so RefreshDatabase assertions work;
 *   domain callers still invoke notify() after successful business work so a
 *   rolled-back domain transaction never schedules a notification.
 * - Persistence failures are logged and never rethrown to domain callers.
 * - Deduplicate by optional unique event_key (business-event instance).
 */
class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notifications
    ) {}

    /**
     * Schedule a localized citizen notification after successful commit.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $replace
     */
    public function notify(
        int $userId,
        NotificationType $type,
        array $data = [],
        array $replace = [],
        ?string $eventKey = null,
    ): void {
        if ($type->isLegacyEmissionSuppressed()) {
            Log::warning('notification.emission_suppressed', [
                'type' => $type->value,
                'user_id' => $userId,
                'event_key' => $eventKey,
            ]);

            return;
        }

        $data = NotificationPayload::normalize($type, $data);

        $this->runAfterCommit(function () use ($userId, $type, $data, $replace, $eventKey): void {
            try {
                $this->persistLocalized($userId, $type, $data, $replace, $eventKey);
            } catch (Throwable $e) {
                $this->logFailure($userId, $type, $data, $eventKey, $e);
            }
        });
    }

    /**
     * Immediate create with resolved title/body (tests / rare sync helpers).
     * Domain services should prefer {@see notify()}.
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
        ?array $data = null,
        ?string $eventKey = null,
    ): Notification {
        $enum = is_string($type) ? NotificationType::tryFrom($type) : null;

        if ($enum !== null) {
            $normalized = NotificationPayload::normalize($enum, $data ?? []);

            return $this->persistLocalized($userId, $enum, $normalized, $replace, $eventKey);
        }

        $locale = RecipientNotificationTranslator::localeForUserId($userId);

        return $this->persistRaw(
            $userId,
            RecipientNotificationTranslator::get($titleKey, $replace, $locale),
            RecipientNotificationTranslator::get($bodyKey, $replace, $locale),
            $type,
            $data,
            $eventKey,
        );
    }

    /**
     * Immediate raw create (tests). Prefer {@see notify()} in domain code.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function sendToUser(
        int $userId,
        string $title,
        string $body,
        ?string $type = null,
        ?array $data = null,
        ?string $eventKey = null,
    ): Notification {
        return $this->persistRaw($userId, $title, $body, $type, $data, $eventKey);
    }

    public function notifyApplicationStatusChange(
        LicenseApplication $application,
        ApplicationStatus $newStatus,
        ?int $statusHistoryId = null,
    ): void {
        $type = NotificationType::tryFromApplicationStatus($newStatus);
        if ($type === null) {
            return;
        }

        $eventKey = $statusHistoryId !== null
            ? NotificationEventKey::forApplicationStatusHistory($type, $statusHistoryId)
            : NotificationEventKey::make(
                $type,
                'application:'.$application->id.':status:'.$newStatus->value.':at:'.now()->getTimestamp()
            );

        $this->notify(
            (int) $application->citizen_id,
            $type,
            [
                'application_id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $newStatus->value,
            ],
            [],
            $eventKey,
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

    public function unreadCountForUser(User $user): int
    {
        return $this->notifications->countUnreadForUser($user->id);
    }

    /**
     * @return array{marked_read_count: int, unread_count: int}
     */
    public function markAllAsRead(User $user): array
    {
        $marked = $this->notifications->markAllReadForUser($user->id);

        return [
            'marked_read_count' => $marked,
            'unread_count' => $this->notifications->countUnreadForUser($user->id),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $replace
     */
    private function persistLocalized(
        int $userId,
        NotificationType $type,
        array $data,
        array $replace,
        ?string $eventKey,
    ): Notification {
        if ($eventKey !== null) {
            $existing = $this->notifications->findByEventKey($eventKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        $locale = RecipientNotificationTranslator::localeForUserId($userId);

        return $this->persistRaw(
            $userId,
            RecipientNotificationTranslator::get($type->titleKey(), $replace, $locale),
            RecipientNotificationTranslator::get($type->bodyKey(), $replace, $locale),
            $type->value,
            $data,
            $eventKey,
        );
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function persistRaw(
        int $userId,
        string $title,
        string $body,
        ?string $type,
        ?array $data,
        ?string $eventKey,
    ): Notification {
        if ($eventKey !== null) {
            $existing = $this->notifications->findByEventKey($eventKey);
            if ($existing !== null) {
                return $existing;
            }
        }

        try {
            return $this->notifications->create([
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'type' => $type,
                'read_at' => null,
                'data' => $data,
                'event_key' => $eventKey,
            ]);
        } catch (QueryException $e) {
            if ($eventKey !== null && $this->isUniqueEventKeyViolation($e)) {
                $existing = $this->notifications->findByEventKey($eventKey);
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }
    }

    private function runAfterCommit(callable $callback): void
    {
        // RefreshDatabase wraps each test in a transaction; afterCommit would never
        // fire during assertions. Run immediately under PHPUnit. Production uses
        // true after-commit so nested domain transactions cannot leave orphans.
        if (app()->runningUnitTests() || DB::transactionLevel() === 0) {
            $callback();

            return;
        }

        DB::afterCommit($callback);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logFailure(
        int $userId,
        NotificationType $type,
        array $data,
        ?string $eventKey,
        Throwable $e,
    ): void {
        Log::error('notification.persist_failed', [
            'type' => $type->value,
            'user_id' => $userId,
            'event_key' => $eventKey,
            'entity_ids' => $this->safeEntityIds($data),
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int|string>
     */
    private function safeEntityIds(array $data): array
    {
        $ids = [];
        foreach ([
            'application_id',
            'document_id',
            'payment_id',
            'license_id',
            'fine_id',
            'test_result_id',
            'appointment_id',
        ] as $key) {
            if (isset($data[$key]) && (is_int($data[$key]) || is_string($data[$key]))) {
                $ids[$key] = $data[$key];
            }
        }

        return $ids;
    }

    private function isUniqueEventKeyViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'event_key')
            || str_contains($message, 'notifications_event_key')
            || (string) $e->getCode() === '23000';
    }
}

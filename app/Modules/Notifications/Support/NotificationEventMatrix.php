<?php

namespace App\Modules\Notifications\Support;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;

/**
 * Source of truth for citizen in-app notification coverage.
 *
 * Spam rule: notify for meaningful business lifecycle events only — not for
 * reads, polling, uploads of each document, mark-read, language changes, or
 * AI Agent read-only answers.
 *
 * @phpstan-type MatrixRow array{
 *     type: string,
 *     domain: string,
 *     coverage: 'implemented'|'legacy'|'deferred'|'silent'|'wired_pending_caller',
 *     phase: 'N1'|'N2'|'legacy'|'deferred'|'silent',
 *     notes: string
 * }
 */
final class NotificationEventMatrix
{
    /**
     * @return list<MatrixRow>
     */
    public static function entries(): array
    {
        return [
            // PROFILE
            ['type' => 'profile.approved', 'domain' => 'profile', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'ProfileReviewService::approve'],
            ['type' => 'profile.rejected', 'domain' => 'profile', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'ProfileReviewService::reject'],
            ['type' => 'profile.ordinary_update', 'domain' => 'profile', 'coverage' => 'silent', 'phase' => 'silent', 'notes' => 'Citizen edits profile fields — API feedback only'],

            // ACCOUNT
            ['type' => 'account.activated', 'domain' => 'account', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'DashboardCitizenService::activate'],
            ['type' => 'account.deactivated', 'domain' => 'account', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'DashboardCitizenService::deactivate'],

            // APPLICATION
            ['type' => 'application.created', 'domain' => 'application', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'ApplicationService draft create'],
            ['type' => 'application.documents_under_review', 'domain' => 'application', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.documents_rejected', 'domain' => 'application', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Distinct from document.rejected'],
            ['type' => 'application.payment_pending', 'domain' => 'application', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.appointment_pending', 'domain' => 'application', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Next-step after payment'],
            ['type' => 'application.approved', 'domain' => 'application', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.waiting_retest', 'domain' => 'application', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.administrative_review', 'domain' => 'application', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.license_issued', 'domain' => 'application', 'coverage' => 'legacy', 'phase' => 'legacy', 'notes' => 'Historical only; new emits license.issued'],
            ['type' => 'application.rejected', 'domain' => 'application', 'coverage' => 'wired_pending_caller', 'phase' => 'N2', 'notes' => 'Mapped in status notify; no production transitionStatus caller yet'],
            ['type' => 'application.cancelled', 'domain' => 'application', 'coverage' => 'wired_pending_caller', 'phase' => 'N2', 'notes' => 'Mapped in status notify; no production transitionStatus caller yet'],
            ['type' => 'application.draft', 'domain' => 'application', 'coverage' => 'silent', 'phase' => 'silent', 'notes' => 'Internal draft state'],
            ['type' => 'application.payment_completed', 'domain' => 'application', 'coverage' => 'silent', 'phase' => 'silent', 'notes' => 'Intermediate; payment.completed + next-step notify instead'],
            ['type' => 'application.in_testing', 'domain' => 'application', 'coverage' => 'silent', 'phase' => 'silent', 'notes' => 'Covered by appointment.booked'],

            // DOCUMENT
            ['type' => 'document.approved', 'domain' => 'document', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Per-document decision'],
            ['type' => 'document.rejected', 'domain' => 'document', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Kept with application.documents_rejected'],
            ['type' => 'document.uploaded', 'domain' => 'document', 'coverage' => 'silent', 'phase' => 'silent', 'notes' => 'Citizen already has API/UI feedback — no spam'],

            // PAYMENT
            ['type' => 'payment.completed', 'domain' => 'payment', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'PaymentLifecycleService::completeVerifiedPayment'],
            ['type' => 'payment.failed', 'domain' => 'payment', 'coverage' => 'implemented', 'phase' => 'N2', 'notes' => 'PaymentLifecycleService::markFailed'],
            ['type' => 'payment.under_verification', 'domain' => 'payment', 'coverage' => 'implemented', 'phase' => 'N2', 'notes' => 'PaymentLifecycleService::markUnderVerification'],
            ['type' => 'payment.pending_created', 'domain' => 'payment', 'coverage' => 'silent', 'phase' => 'silent', 'notes' => 'Checkout start — citizen initiated'],

            // APPOINTMENT
            ['type' => 'appointment.booked', 'domain' => 'appointment', 'coverage' => 'implemented', 'phase' => 'N2', 'notes' => 'AppointmentService::book (+ AI Agent)'],
            ['type' => 'appointment.rescheduled', 'domain' => 'appointment', 'coverage' => 'implemented', 'phase' => 'N2', 'notes' => 'AppointmentService::reschedule — single semantic notify'],
            ['type' => 'appointment.cancelled', 'domain' => 'appointment', 'coverage' => 'implemented', 'phase' => 'N2', 'notes' => 'AppointmentService::cancel'],
            ['type' => 'appointment.reminder', 'domain' => 'appointment', 'coverage' => 'deferred', 'phase' => 'deferred', 'notes' => 'No product timing rule / scheduler — defer N2+'],

            // TEST
            ['type' => 'test_result.passed', 'domain' => 'test', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'With distinct next-step status notify'],
            ['type' => 'test_result.failed', 'domain' => 'test', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'With waiting_retest when applicable'],
            ['type' => 'test_result.no_show', 'domain' => 'test', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'With waiting_retest when applicable'],

            // LICENSE
            ['type' => 'license.issued', 'domain' => 'license', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'New/renewal/replacement issuance'],
            ['type' => 'license.blocked', 'domain' => 'license', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Block decision'],
            ['type' => 'license.unblocked', 'domain' => 'license', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'Unblock decision'],
            ['type' => 'license.expired', 'domain' => 'license', 'coverage' => 'implemented', 'phase' => 'N2', 'notes' => 'LicenseLifecycleService::expireIfNeeded'],
            ['type' => 'license.renewed_type', 'domain' => 'license', 'coverage' => 'silent', 'phase' => 'silent', 'notes' => 'Issuance path already emits license.issued'],
            ['type' => 'license.replaced_type', 'domain' => 'license', 'coverage' => 'silent', 'phase' => 'silent', 'notes' => 'Issuance path already emits license.issued'],

            // FINE
            ['type' => 'fine.created', 'domain' => 'fine', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'FineService::create'],
            ['type' => 'fine.paid', 'domain' => 'fine', 'coverage' => 'implemented', 'phase' => 'N1', 'notes' => 'FineService::update paid'],
            ['type' => 'fine.cancelled', 'domain' => 'fine', 'coverage' => 'implemented', 'phase' => 'N2', 'notes' => 'FineService::update cancelled'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function implementedMachineTypes(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['type'],
            array_filter(
                self::entries(),
                static fn (array $row): bool => in_array($row['coverage'], ['implemented', 'legacy', 'wired_pending_caller'], true)
                    && str_contains($row['type'], '.')
                    && NotificationType::tryFrom($row['type']) !== null
            )
        ));
    }

    /**
     * @return list<string>
     */
    public static function n1Types(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['type'],
            array_filter(self::entries(), static fn (array $row): bool => $row['phase'] === 'N1')
        ));
    }

    /**
     * @return list<string>
     */
    public static function n2Types(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['type'],
            array_filter(self::entries(), static fn (array $row): bool => $row['phase'] === 'N2')
        ));
    }

    /**
     * Application statuses that intentionally do not emit notifications.
     *
     * @return list<string>
     */
    public static function silentApplicationStatuses(): array
    {
        return [
            ApplicationStatus::Draft->value,
            ApplicationStatus::PaymentCompleted->value,
            ApplicationStatus::InTesting->value,
            ApplicationStatus::LicenseIssued->value, // license.issued instead
        ];
    }
}

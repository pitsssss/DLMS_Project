<?php

namespace App\Modules\Notifications\Support;

/**
 * Code-level catalog of citizen in-app notification events.
 *
 * N1 = currently emitted. N2 = deferred lifecycle gaps (not implemented here).
 *
 * Spam rule: notify for meaningful business lifecycle events only — not for
 * reads, polling, uploads of each document, mark-read, language changes, or
 * AI Agent read-only answers.
 */
final class NotificationEventMatrix
{
    /**
     * @return list<array{
     *     type: string,
     *     domain: string,
     *     phase: 'N1'|'N2'|'legacy',
     *     notes: string
     * }>
     */
    public static function entries(): array
    {
        return [
            // PROFILE
            ['type' => 'profile.approved', 'domain' => 'profile', 'phase' => 'N1', 'notes' => 'Profile review approve'],
            ['type' => 'profile.rejected', 'domain' => 'profile', 'phase' => 'N1', 'notes' => 'Profile review reject'],

            // ACCOUNT
            ['type' => 'account.activated', 'domain' => 'account', 'phase' => 'N1', 'notes' => 'Dashboard citizen activate'],
            ['type' => 'account.deactivated', 'domain' => 'account', 'phase' => 'N1', 'notes' => 'Dashboard citizen deactivate'],

            // APPLICATION
            ['type' => 'application.created', 'domain' => 'application', 'phase' => 'N1', 'notes' => 'Draft created'],
            ['type' => 'application.documents_under_review', 'domain' => 'application', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.documents_rejected', 'domain' => 'application', 'phase' => 'N1', 'notes' => 'Distinct from document.rejected'],
            ['type' => 'application.payment_pending', 'domain' => 'application', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.appointment_pending', 'domain' => 'application', 'phase' => 'N1', 'notes' => 'Distinct next-step after payment'],
            ['type' => 'application.approved', 'domain' => 'application', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.waiting_retest', 'domain' => 'application', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.administrative_review', 'domain' => 'application', 'phase' => 'N1', 'notes' => 'Status transition'],
            ['type' => 'application.license_issued', 'domain' => 'application', 'phase' => 'legacy', 'notes' => 'Historical only; new emits license.issued'],
            ['type' => 'application.rejected', 'domain' => 'application', 'phase' => 'N2', 'notes' => 'Citizen-facing reject if added'],
            ['type' => 'application.cancelled', 'domain' => 'application', 'phase' => 'N2', 'notes' => 'Citizen-facing cancel if added'],

            // DOCUMENT
            ['type' => 'document.approved', 'domain' => 'document', 'phase' => 'N1', 'notes' => 'Per-document decision'],
            ['type' => 'document.rejected', 'domain' => 'document', 'phase' => 'N1', 'notes' => 'Per-document decision; kept with application.documents_rejected'],

            // PAYMENT
            ['type' => 'payment.completed', 'domain' => 'payment', 'phase' => 'N1', 'notes' => 'Kept with next-step status notify'],
            ['type' => 'payment.failed', 'domain' => 'payment', 'phase' => 'N2', 'notes' => 'Gap'],
            ['type' => 'payment.under_verification', 'domain' => 'payment', 'phase' => 'N2', 'notes' => 'Gap'],

            // APPOINTMENT (N2)
            ['type' => 'appointment.booked', 'domain' => 'appointment', 'phase' => 'N2', 'notes' => 'Gap'],
            ['type' => 'appointment.rescheduled', 'domain' => 'appointment', 'phase' => 'N2', 'notes' => 'Gap'],
            ['type' => 'appointment.cancelled', 'domain' => 'appointment', 'phase' => 'N2', 'notes' => 'Gap'],
            ['type' => 'appointment.reminder', 'domain' => 'appointment', 'phase' => 'N2', 'notes' => 'Gap / scheduler'],

            // TEST
            ['type' => 'test_result.passed', 'domain' => 'test', 'phase' => 'N1', 'notes' => 'Kept with status next-step'],
            ['type' => 'test_result.failed', 'domain' => 'test', 'phase' => 'N1', 'notes' => 'Kept with status next-step'],
            ['type' => 'test_result.no_show', 'domain' => 'test', 'phase' => 'N1', 'notes' => 'Kept with status next-step'],

            // LICENSE
            ['type' => 'license.issued', 'domain' => 'license', 'phase' => 'N1', 'notes' => 'Sole issuance notification'],
            ['type' => 'license.blocked', 'domain' => 'license', 'phase' => 'N1', 'notes' => 'Block decision'],
            ['type' => 'license.unblocked', 'domain' => 'license', 'phase' => 'N1', 'notes' => 'Unblock decision'],

            // FINE
            ['type' => 'fine.created', 'domain' => 'fine', 'phase' => 'N1', 'notes' => 'Fine issued'],
            ['type' => 'fine.paid', 'domain' => 'fine', 'phase' => 'N1', 'notes' => 'Fine marked paid'],
        ];
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
}

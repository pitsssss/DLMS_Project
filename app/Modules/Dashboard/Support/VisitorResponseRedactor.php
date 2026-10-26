<?php

namespace App\Modules\Dashboard\Support;

/**
 * Field-level projection for visitor JSON responses.
 * Keeps dashboard structure and workflow fields, and replaces identity values.
 */
final class VisitorResponseRedactor
{
    /**
     * Nested audit trails and similar private collections.
     *
     * @var list<string>
     */
    private const STRIP_ARRAY_KEYS = [
        'audit_logs',
        'audit_log',
    ];

    /**
     * Mutation affordances shown by existing resources.
     *
     * @var list<string>
     */
    private const FORCE_FALSE_KEYS = [
        'can_edit',
        'can_activate',
        'can_deactivate',
        'can_print',
        'can_block',
        'can_unblock',
        'can_verify',
        'can_record_result',
        'can_delete',
        'can_create',
        'can_update',
        'can_assign',
        'can_view_audit_logs',
        'can_open_citizen',
        'can_sync_permissions',
        'can_archive',
        'can_restore',
        'can_update_role',
        'can_update_direct_permissions',
    ];

    /**
     * Secrets, file locations, and provider identifiers. Replaced with null.
     *
     * @var list<string>
     */
    private const NULL_KEYS = [
        'verification_url',
        'verification_token',
        'client_secret',
        'download_url',
        'file_url',
        'file_path',
        'storage_path',
        'preview_url',
        'signed_url',
        'portrait_url',
        'portrait_path',
        'provider_reference',
        'access_token',
        'reset_token',
        'password',
        'remember_token',
        'secret',
        'token',
    ];

    /**
     * Direct identifiers and free-text personal fields.
     *
     * @var list<string>
     */
    private const MASK_KEYS = [
        'national_id',
        'phone',
        'mobile',
        'email',
        'birth_date',
        'date_of_birth',
        'address',
        'governorate',
        'profile_rejection_reason',
        'rejection_reason',
        'block_reason',
        'deactivation_reason',
        'cancellation_reason',
        'failure_message',
        'notes',
        'internal_notes',
        'admin_notes',
        'private_notes',
    ];

    /**
     * @var list<string>
     */
    private const PERSON_NAME_KEYS = [
        'holder_name',
        'citizen_name',
        'employee_name',
        'full_name',
    ];

    /**
     * @var list<string>
     */
    private const PERSON_PARENTS = [
        'citizen',
        'citizen_info',
        'applicant',
        'employee',
        'issued_by',
        'blocked_by',
        'printed_by',
        'performed_by',
        'deactivated_by',
        'actor',
        'holder',
    ];

    /**
     * @return array<mixed>|mixed
     */
    public function redact(mixed $value, ?string $parentKey = null): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $personRecord = ! $isList && (
            array_key_exists('national_id', $value)
            || (
                array_key_exists('phone', $value)
                && array_key_exists('email', $value)
                && array_key_exists('name', $value)
            )
        );

        $redacted = [];
        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? $key : null;

            if ($keyString !== null && in_array($keyString, self::STRIP_ARRAY_KEYS, true) && is_array($item)) {
                $redacted[$key] = [];
                continue;
            }

            if ($keyString !== null && in_array($keyString, self::FORCE_FALSE_KEYS, true) && is_bool($item)) {
                $redacted[$key] = false;
                continue;
            }

            if ($keyString !== null && in_array($keyString, self::NULL_KEYS, true)) {
                $redacted[$key] = null;
                continue;
            }

            if ($keyString !== null && in_array($keyString, self::MASK_KEYS, true)) {
                $redacted[$key] = $this->maskScalar($item);
                continue;
            }

            if ($keyString !== null && $this->shouldPseudonymize($keyString, $parentKey, $personRecord)) {
                $redacted[$key] = $this->pseudonym($item);
                continue;
            }

            if ($keyString === 'reason' && (is_string($item) || is_numeric($item))) {
                $redacted[$key] = $this->maskScalar($item);
                continue;
            }

            $redacted[$key] = $this->redact($item, $keyString);
        }

        return $redacted;
    }

    private function shouldPseudonymize(string $key, ?string $parentKey, bool $personRecord): bool
    {
        if (in_array($key, self::PERSON_NAME_KEYS, true)) {
            return true;
        }

        if ($key !== 'name') {
            return false;
        }

        if ($personRecord) {
            return true;
        }

        return $parentKey !== null && in_array($parentKey, self::PERSON_PARENTS, true);
    }

    private function maskScalar(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return __('messages.visitor.redacted_value');
    }

    private function pseudonym(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $ref = substr(hash('sha256', (string) $value), 0, 6);

        return __('messages.visitor.redacted_name', ['ref' => $ref]);
    }
}

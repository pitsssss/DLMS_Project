<?php

namespace App\Modules\Dashboard\Services\EmployeeSessions;

use App\Enums\EmployeeSessionEndedReason;
use App\Enums\EmployeeSessionStatus;
use App\Models\EmployeeSession;
use Carbon\CarbonInterface;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Central derivation of employee session lifecycle status.
 * Status is not permanently stored (unless ended_reason was persisted for history).
 */
class EmployeeSessionStatusResolver
{
    public function resolve(EmployeeSession $session, ?CarbonInterface $now = null): EmployeeSessionStatus
    {
        $now = $now ?? now();

        if ($session->revoked_at !== null) {
            return EmployeeSessionStatus::Revoked;
        }

        if ($session->logged_out_at !== null) {
            return EmployeeSessionStatus::LoggedOut;
        }

        if ($this->isExpired($session, $now)) {
            return EmployeeSessionStatus::Expired;
        }

        if (! $this->credentialStillValid($session, $now)) {
            return EmployeeSessionStatus::Expired;
        }

        $thresholdMinutes = (int) config('employee_sessions.active_threshold_minutes', 5);
        $lastSeen = $session->last_seen_at ?? $session->logged_in_at;

        if ($lastSeen === null || $lastSeen->lte($now->copy()->subMinutes($thresholdMinutes))) {
            return EmployeeSessionStatus::Idle;
        }

        return EmployeeSessionStatus::Active;
    }

    public function isExpired(EmployeeSession $session, ?CarbonInterface $now = null): bool
    {
        $now = $now ?? now();

        if ($session->expires_at !== null && $session->expires_at->lte($now)) {
            return true;
        }

        $token = $this->resolveToken($session);
        if ($token !== null && $token->expires_at !== null && $token->expires_at->lte($now)) {
            return true;
        }

        return false;
    }

    public function credentialStillValid(EmployeeSession $session, ?CarbonInterface $now = null): bool
    {
        $now = $now ?? now();

        if ($session->revoked_at !== null || $session->logged_out_at !== null) {
            return false;
        }

        if ($this->isExpired($session, $now)) {
            return false;
        }

        $token = $this->resolveToken($session);

        if ($token === null) {
            return false;
        }

        return true;
    }

    public function isStillOpen(EmployeeSession $session, ?CarbonInterface $now = null): bool
    {
        $status = $this->resolve($session, $now);

        return in_array($status, [EmployeeSessionStatus::Active, EmployeeSessionStatus::Idle], true);
    }

    /**
     * Persist ended_reason for missing/expired credentials without inventing a revoke.
     */
    public function reconcileEndedState(EmployeeSession $session, ?CarbonInterface $now = null): bool
    {
        $now = $now ?? now();

        if ($session->revoked_at !== null || $session->logged_out_at !== null) {
            return false;
        }

        if ($session->ended_reason === EmployeeSessionEndedReason::Expired->value
            || $session->ended_reason === EmployeeSessionEndedReason::CredentialMissing->value) {
            // Already reconciled; still ensure expires_at when known.
            if ($session->expires_at === null && $this->isExpired($session, $now)) {
                $session->expires_at = $session->expires_at ?? $now;
                $session->save();

                return true;
            }

            return false;
        }

        $token = $this->resolveToken($session);

        if ($token === null && $session->personal_access_token_id === null) {
            $session->ended_reason = EmployeeSessionEndedReason::CredentialMissing->value;
            if ($session->expires_at === null) {
                $session->expires_at = $now;
            }
            $session->save();

            return true;
        }

        if ($token === null) {
            // FK nullOnDelete already cleared the id; mark missing credential.
            $session->ended_reason = EmployeeSessionEndedReason::CredentialMissing->value;
            if ($session->expires_at === null) {
                $session->expires_at = $now;
            }
            $session->save();

            return true;
        }

        if ($this->isExpired($session, $now)) {
            $session->ended_reason = EmployeeSessionEndedReason::Expired->value;
            if ($session->expires_at === null) {
                $session->expires_at = $token->expires_at ?? $now;
            }
            $session->save();

            return true;
        }

        return false;
    }

    private function resolveToken(EmployeeSession $session): ?PersonalAccessToken
    {
        if ($session->relationLoaded('personalAccessToken')) {
            return $session->personalAccessToken;
        }

        if ($session->personal_access_token_id === null) {
            return null;
        }

        return $session->personalAccessToken()->first();
    }
}

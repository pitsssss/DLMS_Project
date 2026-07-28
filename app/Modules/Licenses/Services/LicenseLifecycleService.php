<?php

namespace App\Modules\Licenses\Services;

use App\Enums\LicenseStatus;
use App\Models\License;
use App\Models\LicenseStatusHistory;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LicenseLifecycleService
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    public function generateVerificationToken(): string
    {
        do {
            $token = Str::random(48);
        } while (License::query()->where('verification_token', $token)->exists());

        return $token;
    }

    public function ensureVerificationToken(License $license): string
    {
        if (is_string($license->verification_token) && $license->verification_token !== '') {
            return $license->verification_token;
        }

        $token = $this->generateVerificationToken();
        $license->verification_token = $token;
        $license->save();

        return $token;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordHistory(
        License $license,
        string $action,
        ?LicenseStatus $from,
        LicenseStatus $to,
        ?User $actor = null,
        ?string $reason = null,
        ?string $source = null,
        ?array $metadata = null,
    ): LicenseStatusHistory {
        return LicenseStatusHistory::query()->create([
            'license_id' => $license->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'action' => $action,
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'performed_by' => $actor?->id,
            'source' => $source,
            'metadata' => $this->sanitizeMetadata($metadata),
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function recordAudit(
        ?User $actor,
        string $action,
        License $license,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        $this->auditLogs->log(
            $actor,
            $action,
            'license',
            $license->id,
            $this->stripSecrets($oldValues),
            $this->stripSecrets($newValues),
        );
    }

    /**
     * Idempotent expiry sync for a single locked license row.
     */
    public function expireIfNeeded(License $license, ?User $actor = null, string $source = 'scheduler'): bool
    {
        if ($license->status !== LicenseStatus::Active) {
            return false;
        }

        if ($license->expiry_date === null) {
            return false;
        }

        $today = app(\App\Support\BusinessClock::class)->now()->toDateString();
        if ($license->expiry_date->toDateString() >= $today) {
            return false;
        }

        $from = $license->status;
        $license->status = LicenseStatus::Expired;
        $license->save();

        $this->recordHistory(
            $license,
            'expired',
            $from,
            LicenseStatus::Expired,
            $actor,
            null,
            $source,
        );

        $this->recordAudit(
            $actor,
            'license.expired',
            $license,
            ['status' => $from->value],
            ['status' => LicenseStatus::Expired->value],
        );

        return true;
    }

    /**
     * @return array{updated: int}
     */
    public function syncExpired(int $chunkSize = 200): array
    {
        $updated = 0;
        $today = app(\App\Support\BusinessClock::class)->now()->toDateString();

        License::query()
            ->where('status', LicenseStatus::Active)
            ->whereDate('expiry_date', '<', $today)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($licenses) use (&$updated): void {
                foreach ($licenses as $license) {
                    DB::transaction(function () use ($license, &$updated): void {
                        $locked = License::query()->whereKey($license->id)->lockForUpdate()->first();
                        if ($locked === null) {
                            return;
                        }

                        if ($this->expireIfNeeded($locked, null, 'scheduler')) {
                            $updated++;
                        }
                    });
                }
            });

        return ['updated' => $updated];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function sanitizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        return $this->stripSecrets($metadata);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function stripSecrets(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        unset(
            $values['verification_token'],
            $values['token'],
            $values['secret'],
            $values['password'],
        );

        return $values;
    }
}

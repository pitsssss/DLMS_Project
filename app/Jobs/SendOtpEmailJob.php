<?php

namespace App\Jobs;

use App\Models\Otp;
use App\Models\User;
use App\Modules\Auth\Services\OtpService;
use App\Services\Mail\BrevoDeliveryException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one persisted email OTP via Brevo HTTPS. The plaintext code is required
 * because otps.code is stored as a one-way hash and cannot be recovered.
 *
 * The job payload is encrypted at rest (ShouldBeEncrypted). Never log otpCode.
 */
class SendOtpEmailJob implements ShouldBeEncrypted, ShouldQueueAfterCommit
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $otpId,
        public readonly string $email,
        public readonly string $purpose,
        private readonly string $otpCode,
        public readonly int $expiresMinutes,
    ) {
        $this->onQueue('mail');
    }

    public function handle(OtpService $otps): void
    {
        $otp = Otp::query()->find($this->otpId);

        if ($otp === null || $otp->verified_at !== null || $otp->expires_at->isPast()) {
            Log::info('otp.email_job_skipped', [
                'otp_id' => $this->otpId,
                'purpose' => $this->purpose,
                'reason' => $otp === null ? 'missing' : ($otp->verified_at !== null ? 'verified' : 'expired'),
            ]);

            return;
        }

        try {
            $otps->deliverQueuedOtpEmail(
                $this->email,
                $this->otpCode,
                $this->expiresMinutes,
                $this->otpId,
                $this->purpose,
            );
        } catch (BrevoDeliveryException $e) {
            if (! $e->retryable && $this->job) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $userId = User::query()->where('email', $this->email)->value('id');

        Log::error('otp.email_job_failed', [
            'otp_id' => $this->otpId,
            'user_id' => $userId,
            'purpose' => $this->purpose,
            'job' => self::class,
            'exception' => $exception ? $exception::class : null,
            'message' => $exception?->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'otpId' => $this->otpId,
            'purpose' => $this->purpose,
            'expiresMinutes' => $this->expiresMinutes,
            'queue' => $this->queue,
        ];
    }
}

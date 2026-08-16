<?php

namespace App\Modules\Auth\Services;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Jobs\SendOtpEmailJob;
use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\User;
use App\Services\Mail\BrevoDeliveryException;
use App\Services\Mail\BrevoErrorCategory;
use App\Services\Mail\BrevoTransactionalEmailClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

class OtpService
{
    public function __construct(
        private readonly BrevoTransactionalEmailClient $brevo,
    ) {}

    public function expiryMinutes(): int
    {
        return max(1, (int) config('otp.expires_minutes', 10));
    }

    public function generateCode(): string
    {
        $fixed = config('otp.fixed_code');
        if ($fixed !== null && $fixed !== '') {
            return (string) $fixed;
        }

        return (string) random_int(100000, 999999);
    }

    public function cleanupPendingEmailOtps(string $email, OtpPurpose $purpose): void
    {
        Otp::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->delete();
    }

    /**
     * @deprecated Legacy phone channel. Kept for backward compatibility.
     */
    public function cleanupPendingPhoneOtps(string $phone, OtpPurpose $purpose): void
    {
        Otp::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->delete();
    }

    /**
     * Persist an email OTP (hashed). Does not send email — use {@see sendEmailOtp()} for registration/forgot flows.
     */
    public function createEmailOtp(string $email, OtpPurpose $purpose): Otp
    {
        $this->cleanupPendingEmailOtps($email, $purpose);

        $plain = $this->generateCode();

        return $this->storeEmailOtpRecord($email, $purpose, $plain);
    }

    /**
     * Create a fresh email OTP and queue delivery of the plain code via mail.
     * Brevo HTTPS I/O runs on the worker. Queue dispatch failures surface as API 503.
     */
    public function sendEmailOtp(string $email, OtpPurpose $purpose): void
    {
        if (config('otp.channel') !== 'email') {
            throw new ApiException('messages.auth.otp_channel_not_configured', 503);
        }

        $this->cleanupPendingEmailOtps($email, $purpose);

        $plain = $this->generateCode();

        $otp = $this->storeEmailOtpRecord($email, $purpose, $plain);

        try {
            SendOtpEmailJob::dispatch(
                otpId: $otp->id,
                email: $email,
                purpose: $purpose->value,
                otpCode: $plain,
                expiresMinutes: $this->expiryMinutes(),
            );
        } catch (Throwable $e) {
            Log::error('otp.email_job_dispatch_failed', [
                'otp_id' => $otp->id,
                'purpose' => $purpose->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $otp->delete();

            throw new ApiException('messages.auth.otp_send_failed', 503);
        }
    }

    /**
     * Render the existing OTP mailable and deliver it through Brevo HTTPS.
     * Invoked by {@see SendOtpEmailJob}. Does not use SMTP.
     */
    public function deliverQueuedOtpEmail(
        string $email,
        string $plainCode,
        int $expiresMinutes,
        int $otpId,
        string $purpose,
    ): void {
        $user = User::query()->where('email', $email)->first(['id', 'name']);

        $mailable = new OtpMail(
            otpCode: $plainCode,
            expiresMinutes: $expiresMinutes,
            userName: $user?->name,
        );

        try {
            $result = $this->brevo->send(
                to: $email,
                subject: $mailable->subjectLine(),
                html: $mailable->render(),
                text: $mailable->renderText(),
            );
        } catch (BrevoDeliveryException $e) {
            Log::warning('otp.email_delivery_failed', [
                'job' => SendOtpEmailJob::class,
                'otp_id' => $otpId,
                'user_id' => $user?->id,
                'purpose' => $purpose,
                'provider' => 'brevo',
                'http_status' => $e->httpStatus,
                'category' => $e->category->value,
            ]);

            throw $e;
        }

        if ($result->success) {
            Log::info('otp.email_delivered', [
                'job' => SendOtpEmailJob::class,
                'otp_id' => $otpId,
                'user_id' => $user?->id,
                'purpose' => $purpose,
                'provider' => 'brevo',
                'http_status' => $result->httpStatus,
                'message_id' => $result->messageId,
            ]);

            return;
        }

        Log::warning('otp.email_delivery_failed', [
            'job' => SendOtpEmailJob::class,
            'otp_id' => $otpId,
            'user_id' => $user?->id,
            'purpose' => $purpose,
            'provider' => 'brevo',
            'http_status' => $result->httpStatus,
            'category' => $result->category?->value,
            'transport_reason' => $result->transportReason,
        ]);

        throw new BrevoDeliveryException(
            'Brevo transactional email delivery failed ('.($result->category?->value ?? 'unknown').').',
            retryable: $result->retryable,
            category: $result->category ?? BrevoErrorCategory::Unknown,
            httpStatus: $result->httpStatus,
            messageId: $result->messageId,
        );
    }

    public function verifyEmailOtp(string $email, string $code, OtpPurpose $purpose): bool
    {
        $otp = Otp::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            throw new ApiException('messages.auth.otp_invalid', 422);
        }

        if ($otp->expires_at->isPast()) {
            throw new ApiException('messages.auth.otp_expired', 422);
        }

        if (! Hash::check($code, $otp->code)) {
            throw new ApiException('messages.auth.otp_wrong', 422);
        }

        $otp->verified_at = Carbon::now();
        $otp->save();

        return true;
    }

    /**
     * @deprecated Legacy phone OTP issuance. Kept for backward compatibility.
     */
    public function issueLegacyPhoneOtp(string $phone, OtpPurpose $purpose): Otp
    {
        $this->cleanupPendingPhoneOtps($phone, $purpose);

        $plain = $this->generateCode();

        $expiresAt = Carbon::now()->addMinutes($this->expiryMinutes());

        $otp = Otp::query()->create([
            'email' => null,
            'phone' => $phone,
            'code' => Hash::make($plain),
            'purpose' => $purpose,
            'expires_at' => $expiresAt,
        ]);

        $this->logOtpForDebug(
            purpose: $purpose,
            plainCode: $plain,
            expiresAt: $expiresAt,
            phone: $phone,
        );

        return $otp;
    }

    /**
     * @deprecated Legacy phone OTP verification. Kept for backward compatibility.
     */
    public function verifyLegacyPhoneOtp(string $phone, string $code, OtpPurpose $purpose): Otp
    {
        $otp = Otp::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('verified_at')
            ->latest('id')
            ->first();

        if (! $otp) {
            throw new ApiException('messages.auth.otp_invalid', 422);
        }

        if ($otp->expires_at->isPast()) {
            throw new ApiException('messages.auth.otp_expired', 422);
        }

        if (! Hash::check($code, $otp->code)) {
            throw new ApiException('messages.auth.otp_wrong', 422);
        }

        $otp->verified_at = Carbon::now();
        $otp->save();

        return $otp;
    }

    protected function storeEmailOtpRecord(string $email, OtpPurpose $purpose, string $plainCode): Otp
    {
        $otp = Otp::query()->create([
            'email' => $email,
            'phone' => null,
            'code' => Hash::make($plainCode),
            'purpose' => $purpose,
            'expires_at' => Carbon::now()->addMinutes($this->expiryMinutes()),
        ]);

        $this->logOtpForDebug(
            purpose: $purpose,
            plainCode: $plainCode,
            expiresAt: $otp->expires_at,
            email: $email,
        );

        return $otp;
    }

    private function logOtpForDebug(
        OtpPurpose $purpose,
        string $plainCode,
        Carbon $expiresAt,
        ?string $email = null,
        ?string $phone = null,
        ?int $userId = null,
    ): void {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            return;
        }

        $recipient = $email ?? $phone ?? 'unknown';

        if ($userId === null) {
            $userQuery = User::query();
            if ($email !== null) {
                $userQuery->where('email', $email);
            } elseif ($phone !== null) {
                $userQuery->where('phone', $phone);
            } else {
                $userQuery->whereRaw('1 = 0');
            }

            $userId = $userQuery->value('id');
        }

        $lines = [
            '[OTP DEBUG]',
            'Purpose: '.$purpose->value,
            'User/Email/Phone: '.$recipient,
        ];

        if ($userId !== null) {
            $lines[] = 'User ID: '.$userId;
        }

        if (! app()->runningInConsole() && app()->bound('request')) {
            $ip = request()->ip();
            if ($ip !== null && $ip !== '') {
                $lines[] = 'Request IP: '.$ip;
            }
        }

        $lines[] = 'OTP Code: '.$plainCode;
        $lines[] = 'Expires At: '.$expiresAt->format('Y-m-d H:i:s');

        Log::info(implode("\n", $lines));
    }
}

<?php

namespace App\Modules\Auth\Services;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpService
{
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
     * Create a fresh email OTP and deliver the plain code via mail. Rolls back the OTP row if sending fails.
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
            $displayName = User::query()->where('email', $email)->value('name');

            Mail::to($email)->send(new OtpMail(
                otpCode: $plain,
                expiresMinutes: $this->expiryMinutes(),
                userName: $displayName,
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send OTP email.', ['email' => $email, 'exception' => $e->getMessage()]);
            $otp->delete();

            throw new ApiException('messages.auth.otp_send_failed', 503);
        }
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

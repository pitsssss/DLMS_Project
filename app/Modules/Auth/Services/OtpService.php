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
            throw new ApiException('OTP channel is not configured for email delivery.', 503);
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

            throw new ApiException('Unable to send verification email. Please try again later.', 503);
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
            throw new ApiException('Invalid or expired verification code.', 422);
        }

        if ($otp->expires_at->isPast()) {
            throw new ApiException('Verification code has expired. Request a new code.', 422);
        }

        if (! Hash::check($code, $otp->code)) {
            throw new ApiException('Invalid verification code.', 422);
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

        return Otp::query()->create([
            'email' => null,
            'phone' => $phone,
            'code' => Hash::make($plain),
            'purpose' => $purpose,
            'expires_at' => Carbon::now()->addMinutes($this->expiryMinutes()),
        ]);
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
            throw new ApiException('Invalid or expired verification code.', 422);
        }

        if ($otp->expires_at->isPast()) {
            throw new ApiException('Verification code has expired. Request a new code.', 422);
        }

        if (! Hash::check($code, $otp->code)) {
            throw new ApiException('Invalid verification code.', 422);
        }

        $otp->verified_at = Carbon::now();
        $otp->save();

        return $otp;
    }

    protected function storeEmailOtpRecord(string $email, OtpPurpose $purpose, string $plainCode): Otp
    {
        return Otp::query()->create([
            'email' => $email,
            'phone' => null,
            'code' => Hash::make($plainCode),
            'purpose' => $purpose,
            'expires_at' => Carbon::now()->addMinutes($this->expiryMinutes()),
        ]);
    }
}

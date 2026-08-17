<?php

namespace App\Support\RateLimiting;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Named HTTP rate limiters for public authentication endpoints.
 * Keys always include client IP so the identifier/email alone cannot be used to bypass limits.
 */
final class AuthRateLimiter
{
    public const CITIZEN_LOGIN = 'citizen-login';

    public const DASHBOARD_LOGIN = 'dashboard-login';

    public const CITIZEN_REGISTER = 'citizen-register';

    public const REGISTRATION_OTP_VERIFY = 'registration-otp-verify';

    public const CITIZEN_LOGIN_PER_IDENTIFIER = 5;

    public const CITIZEN_LOGIN_PER_IP = 20;

    public const DASHBOARD_LOGIN_PER_EMAIL = 5;

    public const DASHBOARD_LOGIN_PER_IP = 15;

    public const CITIZEN_REGISTER_PER_EMAIL = 5;

    public const CITIZEN_REGISTER_PER_IP = 10;

    public const REGISTRATION_OTP_PER_EMAIL = 5;

    public const REGISTRATION_OTP_PER_IP = 20;

    public static function register(): void
    {
        RateLimiter::for(self::CITIZEN_LOGIN, function (Request $request) {
            $ip = self::clientIp($request);
            $identifier = self::normalizedLoginIdentifier($request);

            return [
                Limit::perMinute(self::CITIZEN_LOGIN_PER_IDENTIFIER)
                    ->by(self::CITIZEN_LOGIN.':id:'.$identifier.'|'.$ip),
                Limit::perMinute(self::CITIZEN_LOGIN_PER_IP)
                    ->by(self::CITIZEN_LOGIN.':ip:'.$ip),
            ];
        });

        RateLimiter::for(self::DASHBOARD_LOGIN, function (Request $request) {
            $ip = self::clientIp($request);
            $email = self::normalizedEmail($request);

            return [
                Limit::perMinute(self::DASHBOARD_LOGIN_PER_EMAIL)
                    ->by(self::DASHBOARD_LOGIN.':email:'.$email.'|'.$ip),
                Limit::perMinute(self::DASHBOARD_LOGIN_PER_IP)
                    ->by(self::DASHBOARD_LOGIN.':ip:'.$ip),
            ];
        });

        RateLimiter::for(self::CITIZEN_REGISTER, function (Request $request) {
            $ip = self::clientIp($request);
            $email = self::normalizedEmail($request);

            return [
                Limit::perMinute(self::CITIZEN_REGISTER_PER_EMAIL)
                    ->by(self::CITIZEN_REGISTER.':email:'.$email.'|'.$ip),
                Limit::perMinute(self::CITIZEN_REGISTER_PER_IP)
                    ->by(self::CITIZEN_REGISTER.':ip:'.$ip),
            ];
        });

        RateLimiter::for(self::REGISTRATION_OTP_VERIFY, function (Request $request) {
            $ip = self::clientIp($request);
            $email = self::normalizedEmail($request);

            return [
                Limit::perMinute(self::REGISTRATION_OTP_PER_EMAIL)
                    ->by(self::REGISTRATION_OTP_VERIFY.':email:'.$email.'|'.$ip),
                Limit::perMinute(self::REGISTRATION_OTP_PER_IP)
                    ->by(self::REGISTRATION_OTP_VERIFY.':ip:'.$ip),
            ];
        });
    }

    public static function normalizedLoginIdentifier(Request $request): string
    {
        $email = $request->input('email');
        if (is_string($email) && trim($email) !== '') {
            return self::normalize($email);
        }

        $identifier = $request->input('identifier');
        if (is_string($identifier) && trim($identifier) !== '') {
            return self::normalize($identifier);
        }

        return '';
    }

    public static function normalizedEmail(Request $request): string
    {
        $email = $request->input('email');

        if (! is_string($email)) {
            return '';
        }

        return self::normalize($email);
    }

    public static function clientIp(Request $request): string
    {
        return (string) ($request->ip() ?: '0.0.0.0');
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}

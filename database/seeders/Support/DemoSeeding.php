<?php

namespace Database\Seeders\Support;

/**
 * Central gate for development / hosted-demo dataset seeders.
 *
 * Allowed when APP_ENV is local|testing, or when DEMO_SEEDING_ENABLED is
 * explicitly opted in via config (hosted demo/QA only).
 *
 * Never driven by APP_URL or hostnames.
 */
final class DemoSeeding
{
    public static function isAllowed(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        return (bool) config('dlms.demo_seeding_enabled', false);
    }

    public static function refusalMessage(string $subject): string
    {
        return $subject.' may only run in local/testing, or when DEMO_SEEDING_ENABLED=true.';
    }
}

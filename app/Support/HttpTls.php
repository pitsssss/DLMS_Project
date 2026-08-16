<?php

namespace App\Support;

/**
 * Resolves TLS CA verification for outbound HTTPS clients on hosts
 * where php.ini curl.cainfo / openssl.cafile are unset (common on Windows).
 */
final class HttpTls
{
    /**
     * @return bool|string true for system default, or absolute CA bundle path
     */
    public static function verify(?string $configuredBundle = null): bool|string
    {
        foreach ([$configuredBundle, ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }

        $fallback = storage_path('app/private/certs/cacert.pem');
        if (is_file($fallback)) {
            return $fallback;
        }

        return true;
    }
}

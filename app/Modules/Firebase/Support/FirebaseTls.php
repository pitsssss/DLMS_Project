<?php

namespace App\Modules\Firebase\Support;

/**
 * Resolves TLS CA verification for Google Auth + FCM HTTP on hosts
 * where php.ini curl.cainfo / openssl.cafile are unset (common on Windows).
 */
final class FirebaseTls
{
    /**
     * @return bool|string true for system default, or absolute CA bundle path
     */
    public static function verify(): bool|string
    {
        $configured = config('firebase.http.ca_bundle');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        foreach ([ini_get('curl.cainfo'), ini_get('openssl.cafile')] as $iniPath) {
            if (is_string($iniPath) && $iniPath !== '' && is_file($iniPath)) {
                return $iniPath;
            }
        }

        $fallback = storage_path('app/private/certs/cacert.pem');
        if (is_file($fallback)) {
            return $fallback;
        }

        return true;
    }
}

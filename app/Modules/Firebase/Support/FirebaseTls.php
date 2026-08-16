<?php

namespace App\Modules\Firebase\Support;

use App\Support\HttpTls;

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

        return HttpTls::verify(is_string($configured) ? $configured : null);
    }
}

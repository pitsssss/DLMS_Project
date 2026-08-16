<?php

namespace Tests\Unit;

use App\Support\HttpTls;
use Tests\TestCase;

class HttpTlsTest extends TestCase
{
    public function test_verify_prefers_an_existing_configured_bundle(): void
    {
        $path = storage_path('app/private/certs/cacert.pem');
        if (! is_file($path)) {
            $this->markTestSkipped('Local CA fallback bundle is not present.');
        }

        $this->assertSame($path, HttpTls::verify($path));
    }

    public function test_verify_uses_php_ini_or_fallback_rather_than_disabling_tls(): void
    {
        $resolved = HttpTls::verify();

        $this->assertNotFalse($resolved);
        if (is_string($resolved)) {
            $this->assertFileExists($resolved);
        }
    }
}

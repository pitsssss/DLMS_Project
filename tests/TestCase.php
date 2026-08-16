<?php

namespace Tests;

use App\Services\Mail\BrevoTransactionalEmailClient;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Symfony Request::create() injects Accept-Language: en-us,en;q=0.5 by default.
        // Clear it so HTTP tests match production clients that omit the header
        // (resolver then uses users.language / configured default ar).
        $this->withServerVariables([
            'HTTP_ACCEPT_LANGUAGE' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function fakeSuccessfulBrevoTransactionalEmail(
        array $body = ['messageId' => '<test-otp-message@smtp-relay.mailin.fr>'],
        int $status = 201,
    ): void {
        Http::fake([
            BrevoTransactionalEmailClient::SEND_URL => Http::response($body, $status),
        ]);
    }
}

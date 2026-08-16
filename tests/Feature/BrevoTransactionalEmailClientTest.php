<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Services\Mail\BrevoDeliveryException;
use App\Services\Mail\BrevoErrorCategory;
use App\Services\Mail\BrevoTransactionalEmailClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrevoTransactionalEmailClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.brevo.api_key' => 'test-brevo-api-key',
            'services.brevo.sender_email' => 'noreply@example.com',
            'services.brevo.sender_name' => 'SYRTAK',
        ]);
    }

    public function test_send_posts_https_json_to_brevo_transactional_endpoint(): void
    {
        Http::fake([
            BrevoTransactionalEmailClient::SEND_URL => Http::response([
                'messageId' => '<abc@smtp-relay.mailin.fr>',
            ], 201),
        ]);

        $mailable = new OtpMail(otpCode: '123456', expiresMinutes: 10, userName: 'Lina');

        $result = app(BrevoTransactionalEmailClient::class)->send(
            to: 'citizen@example.com',
            subject: $mailable->subjectLine(),
            html: $mailable->render(),
            text: $mailable->renderText(),
        );

        $this->assertTrue($result->success);
        $this->assertSame(201, $result->httpStatus);
        $this->assertSame('<abc@smtp-relay.mailin.fr>', $result->messageId);

        Http::assertSent(function (Request $request) use ($mailable): bool {
            $body = $request->data();

            return $request->url() === BrevoTransactionalEmailClient::SEND_URL
                && $request->method() === 'POST'
                && $request->hasHeader('api-key')
                && $request->header('Accept') === ['application/json']
                && $request->hasHeader('Content-Type')
                && data_get($body, 'sender.email') === 'noreply@example.com'
                && data_get($body, 'sender.name') === 'SYRTAK'
                && data_get($body, 'to.0.email') === 'citizen@example.com'
                && data_get($body, 'subject') === $mailable->subjectLine()
                && data_get($body, 'htmlContent') === $mailable->render()
                && data_get($body, 'textContent') === $mailable->renderText();
        });
    }

    public function test_missing_api_key_fails_without_leaking_secret(): void
    {
        config(['services.brevo.api_key' => '']);
        Http::fake();

        try {
            app(BrevoTransactionalEmailClient::class)->send(
                'citizen@example.com',
                'Subject',
                '<p>Hi</p>',
            );
            $this->fail('Expected a configuration failure.');
        } catch (BrevoDeliveryException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame(BrevoErrorCategory::Configuration, $e->category);
            $this->assertStringNotContainsString('test-brevo-api-key', $e->getMessage());
            $this->assertStringNotContainsString('api-key', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_missing_sender_email_fails_without_http(): void
    {
        config(['services.brevo.sender_email' => '']);
        Http::fake();

        try {
            app(BrevoTransactionalEmailClient::class)->send(
                'citizen@example.com',
                'Subject',
                '<p>Hi</p>',
            );
            $this->fail('Expected a configuration failure.');
        } catch (BrevoDeliveryException $e) {
            $this->assertFalse($e->retryable);
            $this->assertSame(BrevoErrorCategory::Configuration, $e->category);
        }

        Http::assertNothingSent();
    }

    public function test_authentication_failure_is_not_retryable(): void
    {
        Http::fake([
            BrevoTransactionalEmailClient::SEND_URL => Http::response([
                'code' => 'unauthorized',
                'message' => 'Key not found',
            ], 401),
        ]);

        $result = app(BrevoTransactionalEmailClient::class)->send(
            'citizen@example.com',
            'Subject',
            '<p>Hi</p>',
        );

        $this->assertFalse($result->success);
        $this->assertFalse($result->retryable);
        $this->assertSame(BrevoErrorCategory::Authentication, $result->category);
        $this->assertSame(401, $result->httpStatus);
    }

    public function test_connection_failure_is_retryable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect');
        });

        $result = app(BrevoTransactionalEmailClient::class)->send(
            'citizen@example.com',
            'Subject',
            '<p>Hi</p>',
        );

        $this->assertFalse($result->success);
        $this->assertTrue($result->retryable);
        $this->assertSame(BrevoErrorCategory::Connection, $result->category);
        $this->assertSame('curl_7', $result->transportReason);
    }

    public function test_ssl_certificate_failure_is_not_retryable(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate');
        });

        $result = app(BrevoTransactionalEmailClient::class)->send(
            'citizen@example.com',
            'Subject',
            '<p>Hi</p>',
        );

        $this->assertFalse($result->success);
        $this->assertFalse($result->retryable);
        $this->assertSame(BrevoErrorCategory::Ssl, $result->category);
        $this->assertSame('curl_60', $result->transportReason);
    }
}

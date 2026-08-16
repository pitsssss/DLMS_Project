<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Modules\Auth\Services\OtpService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpDebugLoggingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $loggedMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->withoutMiddleware([ThrottleRequests::class]);
        config([
            'otp.channel' => 'email',
            'otp.fixed_code' => '123456',
        ]);
        Mail::fake();
        $this->fakeSuccessfulBrevoTransactionalEmail();
    }

    private function startCapturingLogs(): void
    {
        $this->loggedMessages = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->loggedMessages[] = $event->message;
        });
    }

    public function test_otp_is_logged_in_testing_environment(): void
    {
        $this->startCapturingLogs();

        app(OtpService::class)->sendEmailOtp('debug@example.com', OtpPurpose::Register);

        $debugLog = collect($this->loggedMessages)->first(
            fn (string $message): bool => str_contains($message, '[OTP DEBUG]')
        );

        $this->assertNotNull($debugLog);
        $this->assertStringContainsString('Purpose: register', (string) $debugLog);
        $this->assertStringContainsString('User/Email/Phone: debug@example.com', (string) $debugLog);
        $this->assertStringContainsString('OTP Code: 123456', (string) $debugLog);
        $this->assertStringContainsString('Expires At:', (string) $debugLog);
    }

    public function test_otp_is_not_logged_in_production_environment(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->startCapturingLogs();

        app(OtpService::class)->sendEmailOtp('prod@example.com', OtpPurpose::ForgotPassword);

        $this->assertFalse(
            collect($this->loggedMessages)->contains(fn (string $message): bool => str_contains($message, '[OTP DEBUG]'))
        );

        $this->app->detectEnvironment(static fn (): string => 'testing');
    }

    public function test_register_and_forgot_password_use_same_otp_debug_logging_path(): void
    {
        $this->startCapturingLogs();

        $this->postJson('/api/auth/register', [
            'name' => 'Test Citizen',
            'email' => 'register-otp@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertCreated()
            ->assertJsonMissing(['otp', 'code', 'otp_code']);

        $user = User::factory()->create(['email' => 'forgot-otp@example.com']);

        $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ])->assertOk()
            ->assertJsonMissing(['otp', 'code', 'otp_code']);

        $this->assertTrue(
            collect($this->loggedMessages)->contains(
                fn (string $message): bool => str_contains($message, '[OTP DEBUG]')
                    && str_contains($message, 'Purpose: register')
                    && str_contains($message, 'OTP Code: 123456')
            )
        );

        $this->assertTrue(
            collect($this->loggedMessages)->contains(
                fn (string $message): bool => str_contains($message, '[OTP DEBUG]')
                    && str_contains($message, 'Purpose: forgot_password')
                    && str_contains($message, 'OTP Code: 123456')
            )
        );
    }

    public function test_register_api_response_does_not_expose_otp_code(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'email' => 'hidden-otp@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertCreated();

        $json = json_encode($response->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('123456', (string) $json);
        $this->assertArrayNotHasKey('otp', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('code', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('debug_otp', $response->json('data') ?? []);
        $this->assertArrayNotHasKey('otp_code', $response->json('data') ?? []);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Jobs\SendOtpEmailJob;
use App\Mail\OtpMail;
use App\Models\Otp;
use App\Models\User;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\OtpService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\PendingMail;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OtpEmailQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->withoutMiddleware([ThrottleRequests::class]);
        config([
            'otp.channel' => 'email',
            'otp.fixed_code' => '123456',
            'otp.expires_minutes' => 10,
        ]);
    }

    public function test_register_queues_otp_mail_job_and_does_not_send_smtp_in_http(): void
    {
        Queue::fake();
        Mail::fake();

        $email = 'queue-register@example.com';

        $this->postJson('/api/auth/register', $this->registerPayload($email))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('messages.auth.register_success'))
            ->assertJsonPath('data.expires_in_minutes', 10)
            ->assertJsonMissing(['otp', 'code', 'otp_code']);

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('otps', [
            'email' => $email,
            'purpose' => OtpPurpose::Register->value,
        ]);

        Mail::assertNothingSent();
        Queue::assertPushedOn('mail', SendOtpEmailJob::class);
        Queue::assertPushed(SendOtpEmailJob::class, function (SendOtpEmailJob $job) use ($email): bool {
            $otp = Otp::query()->where('email', $email)->first();

            return $otp !== null
                && $job->otpId === $otp->id
                && $job->email === $email
                && $job->purpose === OtpPurpose::Register->value
                && $job->expiresMinutes === 10
                && $job->queue === 'mail';
        });
    }

    public function test_register_http_duration_does_not_depend_on_mail_transport(): void
    {
        Queue::fake();
        Mail::fake();

        $this->postJson('/api/auth/register', $this->registerPayload('fast-register@example.com'))
            ->assertCreated();

        Mail::assertNothingSent();
        Queue::assertPushed(SendOtpEmailJob::class);
    }

    public function test_otp_mail_job_sends_existing_bilingual_otp_mailable(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'name' => 'Lina',
            'email' => 'job-mail@example.com',
            'is_active' => false,
        ]);

        $otp = Otp::query()->create([
            'email' => $user->email,
            'phone' => null,
            'code' => 'hashed-placeholder',
            'purpose' => OtpPurpose::Register,
            'expires_at' => now()->addMinutes(10),
        ]);

        $job = new SendOtpEmailJob(
            otpId: $otp->id,
            email: $user->email,
            purpose: OtpPurpose::Register->value,
            otpCode: '123456',
            expiresMinutes: 10,
        );

        $job->handle(app(OtpService::class));

        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use ($user): bool {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && $mail->expiresMinutes === 10
                && $mail->userName === 'Lina'
                && str_contains($html, 'تأكيد البريد الإلكتروني')
                && str_contains($html, 'Verify Your Email Address');
        });
    }

    public function test_rolled_back_registration_does_not_dispatch_otp_mail_job(): void
    {
        Mail::fake();

        $queued = 0;
        Event::listen(JobQueued::class, function () use (&$queued): void {
            $queued++;
        });

        $email = 'rollback-otp@example.com';

        DB::beginTransaction();
        app(AuthService::class)->register($this->registerPayload($email));
        $this->assertSame(0, $queued, 'OTP mail job must wait for the registration transaction to commit.');
        DB::rollBack();

        $this->assertSame(0, $queued);
        Mail::assertNothingSent();
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertDatabaseMissing('otps', ['email' => $email]);
    }

    public function test_smtp_failure_in_job_does_not_roll_back_committed_registration(): void
    {
        Queue::fake();

        $email = 'smtp-fail@example.com';

        $this->postJson('/api/auth/register', $this->registerPayload($email))
            ->assertCreated();

        $job = null;
        Queue::assertPushed(SendOtpEmailJob::class, function (SendOtpEmailJob $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });

        $this->assertInstanceOf(SendOtpEmailJob::class, $job);

        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP timeout'));
        Mail::shouldReceive('to')->once()->andReturn($pending);

        try {
            $job->handle(app(OtpService::class));
            $this->fail('Expected the mail job to throw on SMTP failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('SMTP timeout', $e->getMessage());
        }

        $this->assertDatabaseHas('users', [
            'email' => $email,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('otps', [
            'email' => $email,
            'purpose' => OtpPurpose::Register->value,
        ]);
    }

    public function test_queue_dispatch_failure_returns_503_and_rolls_back_registration(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $email = 'dispatch-fail@example.com';

        $this->postJson('/api/auth/register', $this->registerPayload($email))
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('messages.auth.otp_send_failed'));

        $this->assertDatabaseMissing('users', ['email' => $email]);
        $this->assertDatabaseMissing('otps', ['email' => $email]);
    }

    public function test_register_otp_verification_still_succeeds_after_queued_delivery(): void
    {
        Mail::fake();

        $email = 'verify-queued@example.com';

        $this->postJson('/api/auth/register', $this->registerPayload($email))
            ->assertCreated();

        Mail::assertSent(OtpMail::class);

        $this->postJson('/api/auth/verify-otp', [
            'email' => $email,
            'code' => '123456',
            'purpose' => OtpPurpose::Register->value,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('messages.auth.verify_success'))
            ->assertJsonStructure(['data' => ['user', 'token']]);
    }

    public function test_forgot_password_queues_otp_mail_without_changing_contract(): void
    {
        Queue::fake();
        Mail::fake();

        $user = User::factory()->create(['email' => 'forgot-queue@example.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('messages.auth.forgot_sent'));

        Mail::assertNothingSent();
        Queue::assertPushedOn('mail', SendOtpEmailJob::class);
        Queue::assertPushed(SendOtpEmailJob::class, function (SendOtpEmailJob $job) use ($user): bool {
            return $job->email === $user->email
                && $job->purpose === OtpPurpose::ForgotPassword->value;
        });

        $this->postJson('/api/auth/verify-forgot-password-otp', [
            'email' => $user->email,
            'code' => '123456',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['reset_token']]);
    }

    public function test_forgot_password_does_not_queue_mail_for_unknown_email(): void
    {
        Queue::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Queue::assertNothingPushed();
    }

    public function test_dashboard_forgot_password_queues_otp_mail_without_changing_contract(): void
    {
        Queue::fake();
        Mail::fake();

        $employee = User::factory()->dashboardEmployee('settings_employee')->create([
            'email' => 'dash-forgot-queue@test.sy',
        ]);

        $this->postJson('/api/dashboard/auth/forgot-password', ['email' => $employee->email])
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.forgot_password_sent'));

        Mail::assertNothingSent();
        Queue::assertPushed(SendOtpEmailJob::class, function (SendOtpEmailJob $job) use ($employee): bool {
            return $job->email === $employee->email
                && $job->purpose === OtpPurpose::DashboardForgotPassword->value;
        });
    }

    public function test_stale_otp_job_does_not_send_mail(): void
    {
        Mail::fake();

        $otp = Otp::query()->create([
            'email' => 'stale-otp@example.com',
            'phone' => null,
            'code' => 'hashed-placeholder',
            'purpose' => OtpPurpose::Register,
            'expires_at' => now()->addMinutes(10),
        ]);
        $otpId = $otp->id;
        $otp->delete();

        $job = new SendOtpEmailJob(
            otpId: $otpId,
            email: 'stale-otp@example.com',
            purpose: OtpPurpose::Register->value,
            otpCode: '123456',
            expiresMinutes: 10,
        );

        $job->handle(app(OtpService::class));

        Mail::assertNothingSent();
    }

    public function test_failed_job_logs_safe_identifiers_only(): void
    {
        $logged = [];

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = [
                'level' => $event->level,
                'message' => $event->message,
                'context' => $event->context,
            ];
        });

        $user = User::factory()->create(['email' => 'fail-log@example.com']);

        $job = new SendOtpEmailJob(
            otpId: 99,
            email: $user->email,
            purpose: OtpPurpose::Register->value,
            otpCode: '123456',
            expiresMinutes: 10,
        );

        $job->failed(new RuntimeException('SMTP rejected'));

        $entry = collect($logged)->first(
            fn (array $row): bool => $row['message'] === 'otp.email_job_failed'
        );

        $this->assertNotNull($entry);
        $this->assertSame('error', $entry['level']);
        $this->assertSame($user->id, $entry['context']['user_id']);
        $this->assertSame(OtpPurpose::Register->value, $entry['context']['purpose']);
        $this->assertSame(SendOtpEmailJob::class, $entry['context']['job']);
        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('123456', (string) $encoded);
        $this->assertArrayNotHasKey('otpCode', $entry['context']);
        $this->assertArrayNotHasKey('otp_code', $entry['context']);
    }

    public function test_queued_job_payload_in_database_does_not_contain_plaintext_otp(): void
    {
        config(['queue.default' => 'database']);

        $email = 'encrypted-payload@example.com';

        $this->postJson('/api/auth/register', $this->registerPayload($email))
            ->assertCreated();

        $row = DB::table('jobs')->where('queue', 'mail')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('123456', $row->payload);

        $payload = json_decode($row->payload, true);
        $this->assertIsArray($payload);
        $this->assertSame(SendOtpEmailJob::class, $payload['data']['commandName'] ?? null);
        $this->assertIsString($payload['data']['command'] ?? null);
        $this->assertStringNotContainsString('123456', (string) $payload['data']['command']);
    }

    public function test_job_uses_bounded_retry_encrypted_after_commit_contract(): void
    {
        $job = new SendOtpEmailJob(
            otpId: 1,
            email: 'contract@example.com',
            purpose: OtpPurpose::Register->value,
            otpCode: '123456',
            expiresMinutes: 10,
        );

        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $job);
        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff);
        $this->assertSame(60, $job->timeout);
        $this->assertSame('mail', $job->queue);
        $this->assertArrayNotHasKey('otpCode', $job->__debugInfo());
    }

    /**
     * @return array{name: string, email: string, password: string, password_confirmation: string}
     */
    private function registerPayload(string $email): array
    {
        return [
            'name' => 'Test Citizen',
            'email' => $email,
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ];
    }
}

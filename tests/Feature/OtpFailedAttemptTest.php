<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Exceptions\ApiException;
use App\Models\Otp;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Modules\Auth\Services\OtpService;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class OtpFailedAttemptTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        $this->fakeSuccessfulBrevoTransactionalEmail();
    }

    public function test_wrong_otp_increments_failed_attempts_and_persists_after_422(): void
    {
        $user = $this->citizen('persist@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        $this->assertVerifyFails(
            $user->email,
            '000000',
            OtpPurpose::Register,
            'messages.auth.otp_wrong',
        );

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);

        $this->assertSame(1, $otp->failed_attempts);
        $this->assertNull($otp->invalidated_at);
        $this->assertNull($otp->verified_at);
        $this->assertDatabaseHas('otps', [
            'id' => $otp->id,
            'failed_attempts' => 1,
            'invalidated_at' => null,
        ]);
    }

    public function test_nth_minus_one_wrong_attempts_leave_otp_active(): void
    {
        $user = $this->citizen('still-active@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        $this->attemptWrong($user->email, OtpPurpose::Register, $this->maxAttempts() - 1);

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);

        $this->assertSame($this->maxAttempts() - 1, $otp->failed_attempts);
        $this->assertNull($otp->invalidated_at);
        $this->assertNull($otp->verified_at);
    }

    public function test_nth_wrong_attempt_invalidates_otp(): void
    {
        $user = $this->citizen('threshold@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        $this->attemptWrong($user->email, OtpPurpose::Register, $this->maxAttempts() - 1);

        $this->assertVerifyFails(
            $user->email,
            '000000',
            OtpPurpose::Register,
            'messages.auth.otp_attempts_exceeded',
        );

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);

        $this->assertSame($this->maxAttempts(), $otp->failed_attempts);
        $this->assertNotNull($otp->invalidated_at);
        $this->assertNull($otp->verified_at);
    }

    public function test_correct_otp_is_rejected_after_invalidation(): void
    {
        $user = $this->citizen('after-lock@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);
        $this->invalidateOtp($user->email, OtpPurpose::Register);

        $this->assertVerifyFails(
            $user->email,
            '123456',
            OtpPurpose::Register,
            'messages.auth.otp_attempts_exceeded',
        );

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);

        $this->assertNull($otp->verified_at);
        $this->assertNotNull($otp->invalidated_at);
        $this->assertSame($this->maxAttempts(), $otp->failed_attempts);
    }

    public function test_correct_otp_before_threshold_succeeds_after_previous_failures(): void
    {
        $user = $this->citizen('recover@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        $this->attemptWrong($user->email, OtpPurpose::Register, 2);

        $this->assertTrue($this->otps()->verifyEmailOtp($user->email, '123456', OtpPurpose::Register));

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);

        $this->assertSame(2, $otp->failed_attempts);
        $this->assertNotNull($otp->verified_at);
        $this->assertNull($otp->invalidated_at);
    }

    public function test_resend_starts_new_otp_with_clean_attempt_state(): void
    {
        $user = $this->citizen('resend@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);
        $this->invalidateOtp($user->email, OtpPurpose::Register);

        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);

        $this->assertSame(0, $otp->failed_attempts);
        $this->assertNull($otp->invalidated_at);
        $this->assertNull($otp->verified_at);

        $this->assertTrue($this->otps()->verifyEmailOtp($user->email, '123456', OtpPurpose::Register));

        $otp->refresh();
        $this->assertNotNull($otp->verified_at);
        $this->assertNull($otp->invalidated_at);
    }

    public function test_failed_attempts_are_isolated_by_purpose(): void
    {
        $user = $this->citizen('purpose@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::ForgotPassword);
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::DashboardForgotPassword);

        $this->attemptWrong($user->email, OtpPurpose::Register, 4);

        $register = $this->latestOtp($user->email, OtpPurpose::Register);
        $forgot = $this->latestOtp($user->email, OtpPurpose::ForgotPassword);
        $dashboard = $this->latestOtp($user->email, OtpPurpose::DashboardForgotPassword);

        $this->assertSame(4, $register->failed_attempts);
        $this->assertSame(0, $forgot->failed_attempts);
        $this->assertSame(0, $dashboard->failed_attempts);

        $this->assertTrue($this->otps()->verifyEmailOtp($user->email, '123456', OtpPurpose::ForgotPassword));
        $this->assertTrue($this->otps()->verifyEmailOtp($user->email, '123456', OtpPurpose::DashboardForgotPassword));

        $register->refresh();
        $this->assertSame(4, $register->failed_attempts);
        $this->assertNull($register->verified_at);
    }

    public function test_failed_attempts_are_isolated_by_email(): void
    {
        $userA = $this->citizen('user-a-otp@example.com');
        $userB = $this->citizen('user-b-otp@example.com');
        $this->otps()->sendEmailOtp($userA->email, OtpPurpose::Register);
        $this->otps()->sendEmailOtp($userB->email, OtpPurpose::Register);

        $this->invalidateOtp($userA->email, OtpPurpose::Register);

        $this->assertTrue($this->otps()->verifyEmailOtp($userB->email, '123456', OtpPurpose::Register));

        $otpA = $this->latestOtp($userA->email, OtpPurpose::Register);
        $otpB = $this->latestOtp($userB->email, OtpPurpose::Register);

        $this->assertSame($this->maxAttempts(), $otpA->failed_attempts);
        $this->assertNotNull($otpA->invalidated_at);
        $this->assertNull($otpA->verified_at);
        $this->assertSame(0, $otpB->failed_attempts);
        $this->assertNotNull($otpB->verified_at);
    }

    public function test_changing_client_ip_cannot_bypass_failed_attempt_limit(): void
    {
        $user = $this->citizen('ip-bypass@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        $ips = ['10.10.0.1', '10.10.0.2', '10.10.0.3', '10.10.0.4', '10.10.0.5'];

        foreach ($ips as $index => $ip) {
            $expected = $index === $this->maxAttempts() - 1
                ? __('messages.auth.otp_attempts_exceeded')
                : __('messages.auth.otp_wrong');

            $this->inArabic()
                ->fromIp($ip)
                ->postJson('/api/auth/verify-otp', [
                    'email' => $user->email,
                    'code' => '000000',
                    'purpose' => OtpPurpose::Register->value,
                ])
                ->assertStatus(422)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', $expected);
        }

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);
        $this->assertSame($this->maxAttempts(), $otp->failed_attempts);
        $this->assertNotNull($otp->invalidated_at);

        $this->inArabic()
            ->fromIp('10.10.0.6')
            ->postJson('/api/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
                'purpose' => OtpPurpose::Register->value,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.auth.otp_attempts_exceeded'));

        $otp->refresh();
        $this->assertNull($otp->verified_at);
        $this->assertSame($this->maxAttempts(), $otp->failed_attempts);
    }

    public function test_expired_otp_is_still_rejected_without_invalidation(): void
    {
        $user = $this->citizen('expired@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);
        $otp->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->assertVerifyFails(
            $user->email,
            '123456',
            OtpPurpose::Register,
            'messages.auth.otp_expired',
        );

        $otp->refresh();
        $this->assertSame(0, $otp->failed_attempts);
        $this->assertNull($otp->invalidated_at);
        $this->assertNull($otp->verified_at);
    }

    public function test_verified_otp_cannot_be_reused(): void
    {
        $user = $this->citizen('reuse@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        $this->assertTrue($this->otps()->verifyEmailOtp($user->email, '123456', OtpPurpose::Register));

        $this->assertVerifyFails(
            $user->email,
            '123456',
            OtpPurpose::Register,
            'messages.auth.otp_invalid',
        );

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);
        $this->assertNotNull($otp->verified_at);
        $this->assertNull($otp->invalidated_at);
        $this->assertSame(0, $otp->failed_attempts);
    }

    public function test_missing_otp_does_not_create_or_increment_another_row(): void
    {
        $other = $this->citizen('other-otp@example.com');
        $this->otps()->sendEmailOtp($other->email, OtpPurpose::Register);

        $countBefore = Otp::query()->count();

        $this->assertVerifyFails(
            'missing-otp@example.com',
            '123456',
            OtpPurpose::Register,
            'messages.auth.otp_invalid',
        );

        $this->assertSame($countBefore, Otp::query()->count());
        $this->assertSame(0, $this->latestOtp($other->email, OtpPurpose::Register)->failed_attempts);
    }

    public function test_registration_http_invalidates_after_max_wrong_attempts(): void
    {
        $user = $this->citizen('register-http@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        for ($i = 0; $i < $this->maxAttempts() - 1; $i++) {
            $this->inArabic()
                ->fromIp('11.0.0.'.($i + 1))
                ->postJson('/api/auth/verify-otp', [
                    'email' => $user->email,
                    'code' => '000000',
                ])
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.auth.otp_wrong'));
        }

        $this->inArabic()
            ->fromIp('11.0.0.5')
            ->postJson('/api/auth/verify-otp', [
                'email' => $user->email,
                'code' => '000000',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.auth.otp_attempts_exceeded'));

        $this->inArabic()
            ->fromIp('11.0.0.6')
            ->postJson('/api/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.auth.otp_attempts_exceeded'));
    }

    public function test_forgot_password_otp_invalidation_does_not_issue_reset_token(): void
    {
        $user = $this->citizen('forgot-http@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::ForgotPassword);

        for ($i = 0; $i < $this->maxAttempts(); $i++) {
            $this->fromIp('12.0.0.'.($i + 1))
                ->postJson('/api/auth/verify-forgot-password-otp', [
                    'email' => $user->email,
                    'code' => '000000',
                ])
                ->assertStatus(422);
        }

        $otp = $this->latestOtp($user->email, OtpPurpose::ForgotPassword);
        $this->assertNotNull($otp->invalidated_at);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

        $this->inArabic()
            ->fromIp('12.0.0.6')
            ->postJson('/api/auth/verify-forgot-password-otp', [
                'email' => $user->email,
                'code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.auth.otp_attempts_exceeded'))
            ->assertJsonMissingPath('data.reset_token');

        $this->assertSame(0, PasswordResetToken::query()->where('email', $user->email)->count());
    }

    public function test_dashboard_forgot_password_otp_invalidation_does_not_issue_reset_token(): void
    {
        $employee = User::factory()->dashboardEmployee('settings_employee')->create([
            'email' => 'dash-otp@test.sy',
            'password' => Hash::make('password123'),
        ]);
        $this->otps()->sendEmailOtp($employee->email, OtpPurpose::DashboardForgotPassword);

        for ($i = 0; $i < $this->maxAttempts(); $i++) {
            $this->fromIp('13.0.0.'.($i + 1))
                ->postJson('/api/dashboard/auth/verify-forgot-password-otp', [
                    'email' => $employee->email,
                    'code' => '000000',
                ])
                ->assertStatus(422);
        }

        $otp = $this->latestOtp($employee->email, OtpPurpose::DashboardForgotPassword);
        $this->assertNotNull($otp->invalidated_at);

        $this->fromIp('13.0.0.6')
            ->postJson('/api/dashboard/auth/verify-forgot-password-otp', [
                'email' => $employee->email,
                'code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonMissingPath('data.reset_token');

        $this->assertSame(0, PasswordResetToken::query()->where('email', $employee->email)->count());
    }

    public function test_otp_attempts_exceeded_message_is_bilingual(): void
    {
        $user = $this->citizen('bilingual-otp@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);
        $this->invalidateOtp($user->email, OtpPurpose::Register);

        $this->withHeader('Accept-Language', 'en')
            ->fromIp('14.0.0.1')
            ->postJson('/api/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many incorrect verification attempts. Request a new verification code.');

        $this->withHeader('Accept-Language', 'ar')
            ->fromIp('14.0.0.2')
            ->postJson('/api/auth/verify-otp', [
                'email' => $user->email,
                'code' => '123456',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'تم تجاوز عدد محاولات التحقق المسموح بها. اطلب رمز تحقق جديداً.');
    }

    public function test_invalid_max_attempts_config_cannot_allow_unlimited_guessing(): void
    {
        config(['otp.max_attempts' => 0]);

        $user = $this->citizen('config-zero@example.com');
        $this->otps()->sendEmailOtp($user->email, OtpPurpose::Register);

        $this->assertSame(1, $this->otps()->maxAttempts());

        $this->assertVerifyFails(
            $user->email,
            '000000',
            OtpPurpose::Register,
            'messages.auth.otp_attempts_exceeded',
        );

        $otp = $this->latestOtp($user->email, OtpPurpose::Register);
        $this->assertSame(1, $otp->failed_attempts);
        $this->assertNotNull($otp->invalidated_at);
    }

    private function otps(): OtpService
    {
        return app(OtpService::class);
    }

    private function maxAttempts(): int
    {
        return $this->otps()->maxAttempts();
    }

    private function citizen(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    private function latestOtp(string $email, OtpPurpose $purpose): Otp
    {
        return Otp::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->latest('id')
            ->firstOrFail();
    }

    private function attemptWrong(string $email, OtpPurpose $purpose, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->assertVerifyFails($email, '000000', $purpose, 'messages.auth.otp_wrong');
        }
    }

    private function invalidateOtp(string $email, OtpPurpose $purpose): void
    {
        $this->attemptWrong($email, $purpose, $this->maxAttempts() - 1);
        $this->assertVerifyFails($email, '000000', $purpose, 'messages.auth.otp_attempts_exceeded');
    }

    private function assertVerifyFails(string $email, string $code, OtpPurpose $purpose, string $messageKey): void
    {
        try {
            $this->otps()->verifyEmailOtp($email, $code, $purpose);
            $this->fail('Expected OTP verification to fail.');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(__($messageKey), $e->getMessage());
        }
    }

    private function fromIp(string $ip): self
    {
        return $this->withServerVariables([
            'REMOTE_ADDR' => $ip,
        ]);
    }

    private function inArabic(): self
    {
        return $this->withHeader('Accept-Language', 'ar');
    }
}

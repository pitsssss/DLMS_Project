<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Models\User;
use App\Modules\Auth\Services\OtpService;
use App\Support\RateLimiting\AuthRateLimiter;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesSeeder::class, PermissionsSeeder::class]);
        Cache::flush();
        $this->fakeSuccessfulBrevoTransactionalEmail();
    }

    public function test_citizen_login_under_limit_keeps_existing_401_behavior(): void
    {
        $user = $this->citizenUser('citizen-login@example.com');

        for ($i = 0; $i < AuthRateLimiter::CITIZEN_LOGIN_PER_IDENTIFIER; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
                ->assertStatus(401)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', __('messages.auth.invalid_credentials'));
        }
    }

    public function test_citizen_login_above_limit_returns_429(): void
    {
        $user = $this->citizenUser('citizen-login-limit@example.com');

        $this->repeatCitizenLogin($user->email, AuthRateLimiter::CITIZEN_LOGIN_PER_IDENTIFIER);

        $this->assertTooManyRequests(
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
        );
    }

    public function test_citizen_login_limit_is_isolated_by_identifier(): void
    {
        $userA = $this->citizenUser('user-a@example.com');
        $userB = $this->citizenUser('user-b@example.com');

        $this->repeatCitizenLogin($userA->email, AuthRateLimiter::CITIZEN_LOGIN_PER_IDENTIFIER);

        $this->assertTooManyRequests(
            $this->postJson('/api/auth/login', [
                'email' => $userA->email,
                'password' => 'wrong-password',
            ])
        );

        $this->postJson('/api/auth/login', [
            'email' => $userB->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', __('messages.auth.invalid_credentials'));
    }

    public function test_citizen_login_email_case_and_whitespace_share_the_same_quota(): void
    {
        $user = $this->citizenUser('case-login@example.com');

        $this->postJson('/api/auth/login', [
            'email' => '  CASE-LOGIN@EXAMPLE.COM  ',
            'password' => 'wrong-password',
        ])->assertStatus(401);

        $this->repeatCitizenLogin($user->email, AuthRateLimiter::CITIZEN_LOGIN_PER_IDENTIFIER - 1);

        $this->assertTooManyRequests(
            $this->postJson('/api/auth/login', [
                'identifier' => 'Case-Login@Example.com',
                'password' => 'wrong-password',
            ])
        );
    }

    public function test_dashboard_login_under_limit_keeps_existing_401_behavior(): void
    {
        $employee = $this->dashboardEmployee('dash-login@example.com');

        for ($i = 0; $i < AuthRateLimiter::DASHBOARD_LOGIN_PER_EMAIL; $i++) {
            $this->postJson('/api/dashboard/auth/login', [
                'email' => $employee->email,
                'password' => 'wrong-password',
            ])
                ->assertStatus(401)
                ->assertJsonPath('success', false)
                ->assertJsonPath('message', __('messages.dashboard.invalid_credentials'));
        }
    }

    public function test_dashboard_login_above_limit_returns_429(): void
    {
        $employee = $this->dashboardEmployee('dash-login-limit@example.com');

        $this->repeatDashboardLogin($employee->email, AuthRateLimiter::DASHBOARD_LOGIN_PER_EMAIL);

        $this->assertTooManyRequests(
            $this->postJson('/api/dashboard/auth/login', [
                'email' => $employee->email,
                'password' => 'wrong-password',
            ])
        );
    }

    public function test_dashboard_login_limit_is_isolated_by_email(): void
    {
        $employeeA = $this->dashboardEmployee('dash-a@example.com');
        $employeeB = $this->dashboardEmployee('dash-b@example.com');

        $this->repeatDashboardLogin($employeeA->email, AuthRateLimiter::DASHBOARD_LOGIN_PER_EMAIL);

        $this->assertTooManyRequests(
            $this->postJson('/api/dashboard/auth/login', [
                'email' => $employeeA->email,
                'password' => 'wrong-password',
            ])
        );

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $employeeB->email,
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', __('messages.dashboard.invalid_credentials'));
    }

    public function test_dashboard_login_email_case_shares_the_same_quota(): void
    {
        $employee = $this->dashboardEmployee('dash-case@example.com');

        $this->repeatDashboardLogin('DASH-CASE@EXAMPLE.COM', AuthRateLimiter::DASHBOARD_LOGIN_PER_EMAIL);

        $this->assertTooManyRequests(
            $this->postJson('/api/dashboard/auth/login', [
                'email' => 'dash-case@example.com',
                'password' => 'wrong-password',
            ])
        );
    }

    public function test_register_under_limit_keeps_existing_validation_behavior(): void
    {
        $this->postJson('/api/auth/register', $this->registerPayload('register-once@example.com'))
            ->assertCreated();

        for ($i = 0; $i < AuthRateLimiter::CITIZEN_REGISTER_PER_EMAIL - 1; $i++) {
            $this->postJson('/api/auth/register', $this->registerPayload('register-once@example.com'))
                ->assertStatus(422);
        }
    }

    public function test_register_above_limit_returns_429(): void
    {
        $email = 'register-limit@example.com';

        $this->postJson('/api/auth/register', $this->registerPayload($email))->assertCreated();

        for ($i = 0; $i < AuthRateLimiter::CITIZEN_REGISTER_PER_EMAIL - 1; $i++) {
            $this->postJson('/api/auth/register', $this->registerPayload($email))->assertStatus(422);
        }

        $this->assertTooManyRequests(
            $this->postJson('/api/auth/register', $this->registerPayload($email))
        );
    }

    public function test_register_limit_is_isolated_by_email(): void
    {
        $emailA = 'register-a@example.com';
        $emailB = 'register-b@example.com';

        $this->postJson('/api/auth/register', $this->registerPayload($emailA))->assertCreated();
        for ($i = 0; $i < AuthRateLimiter::CITIZEN_REGISTER_PER_EMAIL - 1; $i++) {
            $this->postJson('/api/auth/register', $this->registerPayload($emailA))->assertStatus(422);
        }

        $this->assertTooManyRequests(
            $this->postJson('/api/auth/register', $this->registerPayload($emailA))
        );

        $this->postJson('/api/auth/register', $this->registerPayload($emailB))
            ->assertCreated();
    }

    public function test_registration_otp_verify_under_limit_keeps_existing_otp_error(): void
    {
        $user = $this->citizenUser('otp-verify@example.com');
        app(OtpService::class)->sendEmailOtp($user->email, OtpPurpose::Register);

        for ($i = 0; $i < AuthRateLimiter::REGISTRATION_OTP_PER_EMAIL - 1; $i++) {
            $this->postJson('/api/auth/verify-otp', [
                'email' => $user->email,
                'code' => '000000',
                'purpose' => OtpPurpose::Register->value,
            ])
                ->assertStatus(422)
                ->assertJsonPath('message', __('messages.auth.otp_wrong'));
        }
    }

    public function test_registration_otp_verify_above_limit_returns_429(): void
    {
        $user = $this->citizenUser('otp-verify-limit@example.com');
        app(OtpService::class)->sendEmailOtp($user->email, OtpPurpose::Register);

        for ($i = 0; $i < AuthRateLimiter::REGISTRATION_OTP_PER_EMAIL; $i++) {
            $this->postJson('/api/auth/verify-otp', [
                'email' => $user->email,
                'code' => '000000',
                'purpose' => OtpPurpose::Register->value,
            ])->assertStatus(422);
        }

        $this->assertTooManyRequests(
            $this->postJson('/api/auth/verify-otp', [
                'email' => $user->email,
                'code' => '000000',
                'purpose' => OtpPurpose::Register->value,
            ])
        );
    }

    public function test_registration_otp_verify_limit_is_isolated_by_email(): void
    {
        $userA = $this->citizenUser('otp-a@example.com');
        $userB = $this->citizenUser('otp-b@example.com');
        $otps = app(OtpService::class);
        $otps->sendEmailOtp($userA->email, OtpPurpose::Register);
        $otps->sendEmailOtp($userB->email, OtpPurpose::Register);

        for ($i = 0; $i < AuthRateLimiter::REGISTRATION_OTP_PER_EMAIL; $i++) {
            $this->postJson('/api/auth/verify-otp', [
                'email' => $userA->email,
                'code' => '000000',
                'purpose' => OtpPurpose::Register->value,
            ])->assertStatus(422);
        }

        $this->assertTooManyRequests(
            $this->postJson('/api/auth/verify-otp', [
                'email' => $userA->email,
                'code' => '000000',
                'purpose' => OtpPurpose::Register->value,
            ])
        );

        $this->postJson('/api/auth/verify-otp', [
            'email' => $userB->email,
            'code' => '000000',
            'purpose' => OtpPurpose::Register->value,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.auth.otp_wrong'));
    }

    public function test_citizen_login_429_is_bilingual(): void
    {
        $user = $this->citizenUser('bilingual-login@example.com');
        $this->repeatCitizenLogin($user->email, AuthRateLimiter::CITIZEN_LOGIN_PER_IDENTIFIER);

        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Too many attempts. Please try again later.');

        Cache::flush();
        $this->repeatCitizenLogin($user->email, AuthRateLimiter::CITIZEN_LOGIN_PER_IDENTIFIER);

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'تم تجاوز عدد المحاولات المسموح به. يرجى المحاولة لاحقاً.');
    }

    /**
     * @return array{name: string, email: string, password: string, password_confirmation: string}
     */
    private function registerPayload(string $email): array
    {
        return [
            'name' => 'Rate Limit Citizen',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }

    private function citizenUser(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }

    private function dashboardEmployee(string $email): User
    {
        return User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => $email,
            'password' => Hash::make('password123'),
        ]);
    }

    private function repeatCitizenLogin(string $email, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }
    }

    private function repeatDashboardLogin(string $email, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->postJson('/api/dashboard/auth/login', [
                'email' => $email,
                'password' => 'wrong-password',
            ])->assertStatus(401);
        }
    }

    private function assertTooManyRequests(TestResponse $response): void
    {
        $response->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('messages.http.too_many_requests'))
            ->assertJsonPath('errors', []);
    }
}

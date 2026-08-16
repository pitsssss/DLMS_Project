<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithDashboard;
use Tests\TestCase;

class DashboardAuthTest extends TestCase
{
    use InteractsWithDashboard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDashboardRbac();
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->fakeSuccessfulBrevoTransactionalEmail();
    }

    public function test_super_admin_can_login(): void
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create([
            'email' => 'super@test.sy',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.login_success'))
            ->assertJsonPath('data.user.user_type', 'admin')
            ->assertJsonPath('data.user.role.name', 'super_admin')
            ->assertJsonStructure(['data' => ['token', 'user' => ['permissions']]]);
    }

    public function test_employee_can_login(): void
    {
        $employee = User::factory()->dashboardEmployee('fines_employee')->create([
            'email' => 'fines@test.sy',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $employee->email,
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.user_type', 'employee')
            ->assertJsonPath('data.user.role.name', 'fines_employee');
    }

    public function test_citizen_cannot_login_to_dashboard(): void
    {
        $citizen = User::factory()->create([
            'email' => 'citizen-dash@test.sy',
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $citizen->email,
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', __('messages.dashboard.citizen_not_allowed'));
    }

    public function test_inactive_employee_cannot_login(): void
    {
        $employee = User::factory()->dashboardEmployee('audit_employee')->create([
            'email' => 'inactive@test.sy',
            'password' => Hash::make('password'),
            'is_active' => false,
        ]);

        $this->postJson('/api/dashboard/auth/login', [
            'email' => $employee->email,
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', __('messages.dashboard.inactive_account'));
    }

    public function test_dashboard_me_returns_permissions_and_modules(): void
    {
        $employee = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        $token = $this->dashboardLoginAs($employee);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/auth/me')
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.me_retrieved'))
            ->assertJsonPath('data.user.role.name', 'profile_document_reviewer')
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'permissions',
                        'dashboard_modules',
                    ],
                ],
            ]);

        $modules = collect($response->json('data.user.dashboard_modules'));
        $this->assertTrue($modules->contains(fn ($m) => $m['key'] === 'profile_reviews' && $m['enabled']));
    }

    public function test_logout_revokes_token(): void
    {
        $admin = User::factory()->dashboardAdmin('super_admin')->create();
        $token = $this->dashboardLoginAs($admin);

        $this->assertSame(1, $admin->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/dashboard/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.logout_success'));

        $this->assertSame(0, $admin->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/dashboard/auth/me')
            ->assertUnauthorized();
    }

    public function test_forgot_password_works_for_dashboard_user(): void
    {
        $employee = User::factory()->dashboardEmployee('settings_employee')->create([
            'email' => 'settings-forgot@test.sy',
        ]);

        $this->postJson('/api/dashboard/auth/forgot-password', [
            'email' => $employee->email,
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.forgot_password_sent'));

        $this->assertDatabaseHas('otps', [
            'email' => $employee->email,
            'purpose' => 'dashboard_forgot_password',
        ]);
    }

    public function test_reset_password_works_for_dashboard_user(): void
    {
        $employee = User::factory()->dashboardEmployee('payment_employee')->create([
            'email' => 'reset-dash@test.sy',
            'password' => Hash::make('oldpass123'),
        ]);
        $employee->createToken('old-dashboard');

        $this->postJson('/api/dashboard/auth/forgot-password', ['email' => $employee->email]);

        $verify = $this->postJson('/api/dashboard/auth/verify-forgot-password-otp', [
            'email' => $employee->email,
            'code' => '123456',
        ])->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.otp_verified'));

        $resetToken = $verify->json('data.reset_token');

        $this->postJson('/api/dashboard/auth/reset-password', [
            'email' => $employee->email,
            'reset_token' => $resetToken,
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.dashboard.password_reset'));

        $employee->refresh();
        $this->assertTrue(Hash::check('newpass123', $employee->password));
        $this->assertSame(0, $employee->tokens()->count());
    }

    public function test_invalid_credentials_return_arabic_message(): void
    {
        User::factory()->dashboardAdmin('super_admin')->create([
            'email' => 'wrong@test.sy',
            'password' => Hash::make('secret'),
        ]);

        $this->postJson('/api/dashboard/auth/login', [
            'email' => 'wrong@test.sy',
            'password' => 'bad',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', __('messages.dashboard.invalid_credentials'));
    }
}

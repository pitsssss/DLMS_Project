<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Models\PasswordResetToken;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_forgot_password_returns_generic_success_when_user_exists(): void
    {
        $user = User::factory()->create(['email' => 'citizen@test.com']);

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('messages.auth.forgot_sent'));

        $this->assertDatabaseHas('otps', [
            'email' => $user->email,
            'purpose' => OtpPurpose::ForgotPassword->value,
        ]);
    }

    public function test_forgot_password_returns_generic_success_when_user_missing(): void
    {
        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nobody@test.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_verify_forgot_password_otp_returns_reset_token(): void
    {
        $user = User::factory()->create(['email' => 'reset@test.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        $response = $this->postJson('/api/auth/verify-forgot-password-otp', [
            'email' => $user->email,
            'code' => '123456',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('messages.auth.forgot_otp_verified'))
            ->assertJsonStructure(['data' => ['reset_token']]);

        $this->assertNotEmpty($response->json('data.reset_token'));
        $this->assertEquals(64, strlen($response->json('data.reset_token')));
    }

    public function test_reset_password_updates_password_and_revokes_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'revoke@test.com',
            'password' => Hash::make('oldpassword123'),
        ]);

        $user->createToken('api-token');

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        $verify = $this->postJson('/api/auth/verify-forgot-password-otp', [
            'email' => $user->email,
            'code' => '123456',
        ]);

        $resetToken = $verify->json('data.reset_token');

        $reset = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $reset->assertOk()
            ->assertJsonPath('message', __('messages.auth.password_reset'));

        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));
        $this->assertFalse(Hash::check('oldpassword123', $user->password));
        $this->assertSame(0, $user->tokens()->count());

        $loginOld = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'oldpassword123',
        ]);
        $loginOld->assertStatus(401);

        $loginNew = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'newpassword123',
        ]);
        $loginNew->assertOk()->assertJsonPath('success', true);
    }

    public function test_reset_token_cannot_be_reused(): void
    {
        $user = User::factory()->create(['email' => 'reuse@test.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);
        $verify = $this->postJson('/api/auth/verify-forgot-password-otp', [
            'email' => $user->email,
            'code' => '123456',
        ]);
        $resetToken = $verify->json('data.reset_token');

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'firstpass123',
            'password_confirmation' => 'firstpass123',
        ])->assertOk();

        $second = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'reset_token' => $resetToken,
            'password' => 'secondpass123',
            'password_confirmation' => 'secondpass123',
        ]);

        $second->assertStatus(422);
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'expired@test.com']);

        PasswordResetToken::query()->create([
            'email' => $user->email,
            'token' => Hash::make('mysecrettoken'),
            'expires_at' => now()->subMinutes(5),
            'used_at' => null,
        ]);

        $response = $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'reset_token' => 'mysecrettoken',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_verify_otp_endpoint_rejects_forgot_password_purpose(): void
    {
        $user = User::factory()->create(['email' => 'mix@test.com']);

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email]);

        $response = $this->postJson('/api/auth/verify-otp', [
            'email' => $user->email,
            'code' => '123456',
            'purpose' => OtpPurpose::ForgotPassword->value,
        ]);

        $response->assertStatus(422);
    }

    public function test_verify_forgot_fails_when_only_register_otp_exists(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Test',
            'email' => 'onlyreg@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/verify-forgot-password-otp', [
            'email' => 'onlyreg@test.com',
            'code' => '123456',
        ]);

        $response->assertStatus(422);
    }
}

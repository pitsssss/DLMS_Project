<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
    }

    public function test_authenticated_citizen_can_get_settings_with_account_and_preferences(): void
    {
        Sanctum::actingAs($this->citizen());

        $response = $this->getJson('/api/settings');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'account' => ['id', 'name', 'email', 'phone', 'national_id', 'profile_status', 'profile_completed'],
                    'preferences' => ['language', 'theme'],
                    'available_languages',
                    'available_themes',
                ],
            ])
            ->assertJsonPath('data.preferences.language', 'ar')
            ->assertJsonPath('data.preferences.theme', 'system');

        $this->assertStringNotContainsString('messages.', $response->getContent());
    }

    public function test_settings_require_authentication(): void
    {
        $this->getJson('/api/settings')->assertUnauthorized();
    }

    public function test_citizen_can_update_language_and_theme(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $response = $this->putJson('/api/settings/preferences', [
            'language' => 'en',
            'theme' => 'dark',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.language', 'en')
            ->assertJsonPath('data.theme', 'dark');

        $this->assertDatabaseHas('users', [
            'id' => $citizen->id,
            'language' => 'en',
            'theme' => 'dark',
        ]);
    }

    public function test_invalid_theme_or_language_returns_validation_error(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->putJson('/api/settings/preferences', [
            'language' => 'fr',
            'theme' => 'neon',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['language', 'theme']);
    }

    public function test_citizen_can_change_password_and_old_password_stops_working(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->putJson('/api/settings/change-password', [
            'current_password' => 'password',
            'new_password' => 'new-secure-pass',
            'new_password_confirmation' => 'new-secure-pass',
        ])->assertOk()
            ->assertJsonPath('success', true);

        $citizen->refresh();

        $this->assertTrue(Hash::check('new-secure-pass', $citizen->password));
        $this->assertFalse(Hash::check('password', $citizen->password));
    }

    public function test_wrong_current_password_returns_arabic_error(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->putJson('/api/settings/change-password', [
            'current_password' => 'wrong-password',
            'new_password' => 'new-secure-pass',
            'new_password_confirmation' => 'new-secure-pass',
        ])->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'كلمة المرور الحالية غير صحيحة.');
    }

    public function test_new_password_works_for_login_after_change(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $this->putJson('/api/settings/change-password', [
            'current_password' => 'password',
            'new_password' => 'new-secure-pass',
            'new_password_confirmation' => 'new-secure-pass',
        ])->assertOk();

        $login = $this->postJson('/api/auth/login', [
            'email' => $citizen->email,
            'password' => 'new-secure-pass',
        ]);

        $login->assertOk()->assertJsonPath('success', true);
    }
}

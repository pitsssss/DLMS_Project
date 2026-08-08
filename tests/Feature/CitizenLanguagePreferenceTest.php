<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenLanguagePreferenceTest extends TestCase
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

    public function test_auth_me_exposes_language(): void
    {
        Sanctum::actingAs($this->citizen(['language' => 'ar']));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.language', 'ar');
    }

    public function test_login_user_payload_exposes_language(): void
    {
        $citizen = $this->citizen([
            'language' => 'en',
            'password' => 'password',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $citizen->email,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.language', 'en')
            ->assertJsonPath('success', true);
    }

    public function test_put_language_en_persists_without_rotating_token(): void
    {
        $citizen = $this->citizen(['language' => 'ar']);

        $login = $this->postJson('/api/auth/login', [
            'email' => $citizen->email,
            'password' => 'password',
        ])->assertOk();

        $token = (string) $login->json('data.token');
        $this->assertNotSame('', $token);

        $tokenIdBefore = PersonalAccessToken::findToken($token)?->id;
        $this->assertNotNull($tokenIdBefore);

        $this->withToken($token)
            ->putJson('/api/settings/preferences', ['language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.language', 'en');

        $this->assertDatabaseHas('users', [
            'id' => $citizen->id,
            'language' => 'en',
        ]);

        $this->assertSame($tokenIdBefore, PersonalAccessToken::findToken($token)?->id);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.language', 'en');
    }

    public function test_put_language_ar_persists(): void
    {
        Sanctum::actingAs($this->citizen(['language' => 'en']));

        $this->putJson('/api/settings/preferences', ['language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('data.language', 'ar');
    }

    public function test_invalid_stored_language_remains_impossible_through_settings_api(): void
    {
        Sanctum::actingAs($this->citizen());

        $this->putJson('/api/settings/preferences', ['language' => 'de'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['language']);
    }

    public function test_accept_language_does_not_persist_preference(): void
    {
        $citizen = $this->citizen(['language' => 'ar']);
        Sanctum::actingAs($citizen);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/settings')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.preferences.language', 'ar');

        $citizen->refresh();
        $this->assertSame('ar', $citizen->language);
    }

    public function test_citizen_authorization_unchanged_for_settings(): void
    {
        $this->getJson('/api/settings')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->dashboardEmployee()->create());

        $this->getJson('/api/settings')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function citizen(array $overrides = []): User
    {
        return User::factory()->withApprovedProfile()->create(array_merge([
            'email_verified_at' => now(),
        ], $overrides));
    }
}

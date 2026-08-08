<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RequestLocaleTest extends TestCase
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

    public function test_guest_without_header_resolves_ar(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertHeader('Vary', 'Accept-Language');
    }

    public function test_guest_accept_language_ar_resolves_ar(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar');
    }

    public function test_guest_accept_language_en_resolves_en(): void
    {
        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');
    }

    public function test_en_us_normalizes_to_en(): void
    {
        $this->withHeader('Accept-Language', 'en-US')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');
    }

    public function test_ar_sy_normalizes_to_ar(): void
    {
        $this->withHeader('Accept-Language', 'ar-SY')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar');
    }

    public function test_q_value_negotiation_prefers_supported_locale(): void
    {
        $this->withHeader('Accept-Language', 'en-US,en;q=0.9,ar;q=0.8')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this->withHeader('Accept-Language', 'ar-SY,ar;q=0.9,en;q=0.8')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar');
    }

    public function test_unsupported_header_without_user_falls_back_to_ar(): void
    {
        $this->withHeader('Accept-Language', 'de')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar');
    }

    public function test_authenticated_user_language_en_without_header_resolves_en(): void
    {
        Sanctum::actingAs($this->citizen(['language' => 'en']));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.language', 'en');
    }

    public function test_authenticated_user_language_ar_without_header_resolves_ar(): void
    {
        Sanctum::actingAs($this->citizen(['language' => 'ar']));

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('data.language', 'ar');
    }

    public function test_accept_language_overrides_stored_preference_for_request_only(): void
    {
        $citizen = $this->citizen(['language' => 'ar']);
        Sanctum::actingAs($citizen);

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('data.language', 'ar');

        $this->assertDatabaseHas('users', [
            'id' => $citizen->id,
            'language' => 'ar',
        ]);
    }

    public function test_unsupported_header_does_not_override_stored_user_preference(): void
    {
        Sanctum::actingAs($this->citizen(['language' => 'en']));

        $this->withHeader('Accept-Language', 'de')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');
    }

    public function test_malformed_accept_language_falls_back_safely(): void
    {
        $this->withHeader('Accept-Language', '@@@')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar');

        Sanctum::actingAs($this->citizen(['language' => 'en']));

        $this->withHeader('Accept-Language', "\x00")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');
    }

    public function test_existing_vary_header_is_preserved_and_accept_language_appended(): void
    {
        Route::middleware('locale')->get('/api/_locale_vary_probe', function () {
            return response()->json(['ok' => true])->header('Vary', 'Authorization');
        });

        $response = $this->getJson('/api/_locale_vary_probe');

        $response->assertOk()
            ->assertHeader('Content-Language', 'ar');

        $vary = $response->headers->get('Vary');
        $this->assertNotNull($vary);
        $parts = array_map('trim', explode(',', $vary));
        $this->assertContains('Authorization', $parts);
        $this->assertContains('Accept-Language', $parts);
    }

    public function test_locale_does_not_leak_between_requests(): void
    {
        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');

        $this->assertSame('ar', app()->getLocale());

        $this->flushHeaders();

        $this->getJson('/api/ping')
            ->assertOk()
            ->assertHeader('Content-Language', 'ar');

        $this->assertSame('ar', app()->getLocale());
    }

    public function test_dashboard_routes_do_not_use_citizen_locale_middleware(): void
    {
        $response = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/dashboard/auth/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(401);
        $this->assertFalse($response->headers->has('Content-Language'));
    }

    public function test_english_validation_uses_en_pack_for_guest_login(): void
    {
        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'The submitted data failed validation.');
    }

    public function test_arabic_validation_remains_compatible_for_guest_login(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'فشل التحقق من البيانات المدخلة.')
            ->assertHeader('Content-Language', 'ar');
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

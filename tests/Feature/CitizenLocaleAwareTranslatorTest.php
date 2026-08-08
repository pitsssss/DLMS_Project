<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Msg;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenLocaleAwareTranslatorTest extends TestCase
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

    public function test_arabic_citizen_request_still_returns_arabic_envelope(): void
    {
        $citizen = $this->citizen(['language' => 'ar']);

        $login = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/auth/login', [
                'email' => $citizen->email,
                'password' => 'password',
            ])
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('message', 'تم تسجيل الدخول بنجاح.');

        $this->assertStringNotContainsString('messages.', $login->getContent());
    }

    public function test_english_request_returns_english_envelope(): void
    {
        $citizen = $this->citizen(['language' => 'en']);

        $response = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/auth/login', [
                'email' => $citizen->email,
                'password' => 'password',
            ])
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Signed in successfully.');

        $this->assertStringNotContainsString('messages.', $response->getContent());
    }

    public function test_authenticated_english_settings_envelope_is_english(): void
    {
        Sanctum::actingAs($this->citizen(['language' => 'en']));

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/settings')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Settings retrieved successfully.');

        $payload = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/settings')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('messages.', $payload);
    }

    public function test_dashboard_msg_labels_remain_arabic_when_app_locale_is_english(): void
    {
        app()->setLocale('en');

        $this->assertSame('فعالة', Msg::get('licenses.statuses.active'));
    }

    public function test_dashboard_login_still_returns_arabic_when_accept_language_is_english(): void
    {
        $employee = User::factory()->dashboardEmployee()->create([
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        // Dashboard routes intentionally omit citizen locale middleware.
        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/dashboard/auth/login', [
                'email' => $employee->email,
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'تم تسجيل الدخول بنجاح.');
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

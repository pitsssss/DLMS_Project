<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\LicenseStatus;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Positive rate-limit evidence: ThrottleRequests stays ENABLED.
 */
class RateLimitEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
            TestTypesSeeder::class,
            FeesSeeder::class,
        ]);
        Cache::flush();
        $this->fakeSuccessfulBrevoTransactionalEmail();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_forgot_password_returns_429_after_configured_limit(): void
    {
        $user = User::factory()->create(['email' => 'rate-forgot@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
                ->assertOk();
        }

        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertStatus(429);
    }

    public function test_public_license_verification_returns_429_after_configured_limit(): void
    {
        $token = $this->makeVerifiableLicenseToken();

        $this->getJson('/api/licenses/verify/'.$token)->assertOk();

        for ($i = 0; $i < 29; $i++) {
            $this->getJson('/api/licenses/verify/'.$token)->assertOk();
        }

        $this->getJson('/api/licenses/verify/'.$token)->assertStatus(429);
    }

    public function test_payment_initiation_returns_429_after_configured_limit(): void
    {
        [$citizen, $application] = $this->citizenInPaymentPending();
        Sanctum::actingAs($citizen);

        $this->postJson("/api/applications/{$application->id}/payments", [])
            ->assertOk();

        for ($i = 0; $i < 14; $i++) {
            $this->postJson("/api/applications/{$application->id}/payments", [])
                ->assertOk();
        }

        $this->postJson("/api/applications/{$application->id}/payments", [])
            ->assertStatus(429);
    }

    public function test_ai_agent_message_returns_429_after_configured_limit(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($citizen);

        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')
            ->zeroOrMoreTimes()
            ->andReturn([
                'intent' => 'general_help',
                'reply' => 'ok',
                'confidence' => 0.9,
            ]);
        $this->instance(GeminiAgentClient::class, $mock);

        $this->postJson('/api/ai-agent/message', ['message' => 'مرحبا'])
            ->assertOk();

        for ($i = 0; $i < 29; $i++) {
            $this->postJson('/api/ai-agent/message', ['message' => 'مرحبا '.$i])
                ->assertOk();
        }

        $this->postJson('/api/ai-agent/message', ['message' => 'تجاوز الحد'])
            ->assertStatus(429);
    }

    private function citizenInPaymentPending(): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-RL-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->value('id'),
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->value('id'),
            'status' => ApplicationStatus::PaymentPending,
            'submitted_at' => now(),
        ]);

        return [$citizen, $application];
    }

    private function makeVerifiableLicenseToken(): string
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        $licenseTypeId = (int) LicenseType::query()->where('code', 'private')->value('id');
        $serviceTypeId = (int) ServiceType::query()->where('code', 'new_license')->value('id');

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-RLV-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $serviceTypeId,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now(),
            'issued_at' => now(),
        ]);

        $token = Str::random(48);
        License::query()->create([
            'license_number' => 'LIC-RL-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseTypeId,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addYears(10)->toDateString(),
            'verification_token' => $token,
        ]);

        return $token;
    }
}

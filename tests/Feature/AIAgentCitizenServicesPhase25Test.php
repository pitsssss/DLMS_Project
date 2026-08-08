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
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class AIAgentCitizenServicesPhase25Test extends TestCase
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
            RequiredDocumentsSeeder::class,
            AppointmentSlotsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function mockGemini(): void
    {
        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn(null);
        $this->instance(GeminiAgentClient::class, $mock);
    }

    /**
     * @return array{0: User, 1: License}
     */
    private function citizenWithLicense(string $prefix = 'LIC'): array
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $app = LicenseApplication::query()->create([
            'application_number' => 'APP-P25-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'issued_at' => now()->subYears(9),
        ]);
        $license = License::query()->create([
            'license_number' => $prefix.'-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $app->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->subYears(9)->toDateString(),
            'expiry_date' => now()->addDays(20)->toDateString(),
        ]);

        return [$citizen, $license];
    }

    public function test_new_license_e2e_arabic_confirm_via_interactions(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk();
        $this->assertSame('create_new_license_application', $ask->json('data.intent'));
        $sessionId = (int) $ask->json('data.session_id');

        $typed = $this->postJson('/api/ai-agent/message', [
            'session_id' => $sessionId,
            'message' => 'خاصة',
        ])->assertOk();

        $this->assertSame('create_application', $typed->json('data.pending_action.name'));
        $actionId = (int) $typed->json('data.pending_action.id');

        $confirm = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'confirm_pending_action',
            'action_id' => $actionId,
        ])->assertOk();

        $this->assertNotEmpty($confirm->json('data.result.application_number'));
        $this->assertSame(1, LicenseApplication::query()->where('citizen_id', $citizen->id)->count());
    }

    public function test_renew_lost_damaged_e2e_and_english(): void
    {
        foreach ([
            ['بدي جدد رخصتي', 'create_renew_license_application', 'renew_license'],
            ['ضاعت رخصتي', 'create_lost_replacement_application', 'lost_replacement'],
            ['رخصتي تالفة', 'create_damaged_replacement_application', 'damaged_replacement'],
        ] as [$message, $intent, $service]) {
            [$citizen, $license] = $this->citizenWithLicense(strtoupper(substr($service, 0, 3)));
            Sanctum::actingAs($citizen);
            $this->mockGemini();

            $ask = $this->postJson('/api/ai-agent/message', ['message' => $message])->assertOk();
            $this->assertSame($intent, $ask->json('data.intent'));
            $this->assertSame('create_application', $ask->json('data.pending_action.name'));
            $this->assertSame($service, $ask->json('data.pending_action.arguments.service_type_code'));
            $this->assertSame($license->id, (int) $ask->json('data.pending_action.arguments.related_license_id'));

            $actionId = (int) $ask->json('data.pending_action.id');
            $confirm = $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();
            $this->assertNotEmpty($confirm->json('data.result.application_number'));
        }

        [$enCitizen, $enLicense] = $this->citizenWithLicense('EN');
        Sanctum::actingAs($enCitizen);
        $this->mockGemini();
        $en = $this->postJson('/api/ai-agent/message', [
            'message' => 'I want to renew my license',
        ])->assertOk();
        $this->assertSame('create_renew_license_application', $en->json('data.intent'));
        $this->assertSame($enLicense->id, (int) $en->json('data.pending_action.arguments.related_license_id'));
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', (string) $en->json('data.reply'));
    }

    public function test_multi_license_selection_for_lost_replacement(): void
    {
        [$citizen, $a] = $this->citizenWithLicense('A');
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $app = LicenseApplication::query()->create([
            'application_number' => 'APP-P25-B',
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'issued_at' => now()->subYears(8),
        ]);
        $b = License::query()->create([
            'license_number' => 'LIC-B-'.strtoupper(Str::random(4)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $app->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->subYears(8)->toDateString(),
            'expiry_date' => now()->addDays(15)->toDateString(),
        ]);

        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', [
            'message' => 'ضاعت رخصتي',
        ])->assertOk();

        $this->assertSame('license_selection_required', $ask->json('data.message_type'));
        $this->assertCount(2, $ask->json('data.ui_payload.licenses'));
        $sessionId = (int) $ask->json('data.session_id');
        $token = (string) $ask->json('data.ui_payload.licenses.0.selection_token');

        $selected = $this->postJson("/api/ai-agent/sessions/{$sessionId}/interactions", [
            'action' => 'select_license',
            'selection_token' => $token,
        ])->assertOk();

        $this->assertSame('license_service_confirmation_required', $selected->json('data.message_type'));
        $this->assertSame('create_application', $selected->json('data.pending_action.name'));
        $this->assertSame('lost_replacement', $selected->json('data.pending_action.arguments.service_type_code'));
        $this->assertContains(
            (int) $selected->json('data.pending_action.arguments.related_license_id'),
            [$a->id, $b->id]
        );
    }

    public function test_payment_status_and_start_payment_paths(): void
    {
        $citizen = $this->citizen();
        $app = LicenseApplication::query()->create([
            'application_number' => 'APP-PAY-P25',
            'citizen_id' => $citizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::PaymentPending,
        ]);
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $status = $this->postJson('/api/ai-agent/message', [
            'message' => 'حالة الدفع',
        ])->assertOk();
        $this->assertSame('get_payment_status', $status->json('data.intent'));
        $this->assertTrue((bool) $status->json('data.result.is_awaiting_payment'));

        $pay = $this->postJson('/api/ai-agent/message', [
            'session_id' => $status->json('data.session_id'),
            'message' => 'بدي ادفع',
        ])->assertOk();
        $this->assertSame('start_payment', $pay->json('data.intent'));
        $this->assertTrue((bool) $pay->json('data.requires_confirmation'));
        $actionId = (int) $pay->json('data.pending_action.id');

        $confirm = $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();
        $this->assertNotNull($confirm->json('data.result.payment_id'));

        $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertStatus(422);

        $citizen2 = $this->citizen();
        LicenseApplication::query()->create([
            'application_number' => 'APP-PAY-DRAFT',
            'citizen_id' => $citizen2->id,
            'license_type_id' => $app->license_type_id,
            'service_type_id' => $app->service_type_id,
            'status' => ApplicationStatus::Draft,
        ]);
        Sanctum::actingAs($citizen2);
        $blocked = $this->postJson('/api/ai-agent/message', ['message' => 'بدي ادفع'])->assertOk();
        $this->assertNull($blocked->json('data.pending_action'));
        $this->assertStringContainsString('لا يمكنك', (string) $blocked->json('data.reply'));
    }

    public function test_licenses_fines_tests_empty_and_bilingual(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $licenses = $this->postJson('/api/ai-agent/message', ['message' => 'رخصي'])->assertOk();
        $this->assertSame('get_licenses', $licenses->json('data.intent'));
        $this->assertIsArray($licenses->json('data.result.items'));

        $fines = $this->postJson('/api/ai-agent/message', [
            'session_id' => $licenses->json('data.session_id'),
            'message' => 'المخالفات',
        ])->assertOk();
        $this->assertSame('get_fines', $fines->json('data.intent'));
        $this->assertSame([], $fines->json('data.result.items'));

        $en = $this->postJson('/api/ai-agent/message', [
            'message' => 'show my licenses',
        ])->assertOk();
        $this->assertSame('get_licenses', $en->json('data.intent'));
        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', (string) $en->json('data.reply'));

        $appCitizen = $this->citizen();
        LicenseApplication::query()->create([
            'application_number' => 'APP-TEST-P25',
            'citizen_id' => $appCitizen->id,
            'license_type_id' => LicenseType::query()->where('code', 'private')->firstOrFail()->id,
            'service_type_id' => ServiceType::query()->where('code', 'new_license')->firstOrFail()->id,
            'status' => ApplicationStatus::AppointmentPending,
        ]);
        Sanctum::actingAs($appCitizen);
        $tests = $this->postJson('/api/ai-agent/message', ['message' => 'إعادة اختبار'])->assertOk();
        $this->assertSame('get_available_tests', $tests->json('data.intent'));
        $this->assertSame('get_available_tests', $tests->json('data.executed_action.name'));
    }

    public function test_duplicate_renew_redirects_away_from_create(): void
    {
        [$citizen] = $this->citizenWithLicense('DUP');
        Sanctum::actingAs($citizen);
        $this->mockGemini();

        $ask = $this->postJson('/api/ai-agent/message', ['message' => 'بدي جدد رخصتي'])->assertOk();
        $actionId = (int) $ask->json('data.pending_action.id');
        $this->postJson("/api/ai-agent/actions/{$actionId}/confirm")->assertOk();

        $again = $this->postJson('/api/ai-agent/message', ['message' => 'بدي جدد رخصتي'])->assertOk();
        $this->assertNotSame('create_application', $again->json('data.pending_action.name'));
        $this->assertTrue(
            str_contains((string) $again->json('data.reply'), 'طلب')
            || $again->json('data.intent') === 'get_application_status'
        );
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
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
use Tests\TestCase;

class CitizenBilingualMessagesTest extends TestCase
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

    public function test_auth_and_profile_messages_are_bilingual(): void
    {
        $citizen = $this->citizen(['language' => 'ar']);

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/auth/login', [
                'email' => $citizen->email,
                'password' => 'password',
            ])
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('message', 'تم تسجيل الدخول بنجاح.');

        Sanctum::actingAs($citizen);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('message', 'تم جلب الملف الشخصي بنجاح.');

        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/auth/login', [
                'email' => $citizen->email,
                'password' => 'password',
            ])
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Signed in successfully.');

        Sanctum::actingAs($citizen);

        $enProfile = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Profile retrieved successfully.');

        $this->assertNoMessageKeyLeak($enProfile->getContent());
    }

    public function test_application_and_document_messages_are_bilingual(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $createdAr = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/applications', [
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
            ])
            ->assertOk()
            ->assertJsonPath('message', 'تم إنشاء طلب رخصة القيادة بنجاح.');

        $applicationId = (int) $createdAr->json('data.id');
        $this->assertNoMessageKeyLeak($createdAr->getContent());

        $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertOk()
            ->assertJsonPath('message', 'تم جلب الوثائق المطلوبة بنجاح.');

        // Second application for English envelope (first remains draft; use different service if needed).
        // Duplicate same combo is blocked — list existing application in EN instead.
        $enList = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/applications')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Driving license applications retrieved successfully.');

        $this->assertNoMessageKeyLeak($enList->getContent());

        $enDocs = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertOk()
            ->assertJsonPath('message', 'Required documents retrieved successfully.');

        $this->assertNoMessageKeyLeak($enDocs->getContent());
    }

    public function test_payment_messages_are_bilingual(): void
    {
        [$citizen, $application] = $this->applicationInStatus(ApplicationStatus::PaymentPending);
        Sanctum::actingAs($citizen);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/applications/{$application->id}/fee")
            ->assertOk()
            ->assertJsonPath('message', 'تم جلب الرسوم المطلوبة بنجاح.');

        $enFee = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/applications/{$application->id}/fee")
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Required fee retrieved successfully.');

        $this->assertNoMessageKeyLeak($enFee->getContent());
    }

    public function test_appointment_and_test_availability_messages_are_bilingual(): void
    {
        [$citizen, $application] = $this->applicationInStatus(ApplicationStatus::AppointmentPending);
        Sanctum::actingAs($citizen);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk()
            ->assertJsonPath('message', 'تم جلب الاختبارات المتاحة بنجاح.')
            ->assertJsonPath('data.tests.0.next_action_label', 'حجز موعد');

        $theoryAr = collect($ar->json('data.tests'))->firstWhere('code', 'theory');
        $this->assertNotNull($theoryAr);
        $this->assertStringContainsString('فحص النظر', (string) $theoryAr['reason']);
        $this->assertStringNotContainsString(':previous_test', (string) $theoryAr['reason']);

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Available tests retrieved successfully.')
            ->assertJsonPath('data.tests.0.next_action_label', 'Book appointment');

        $theoryEn = collect($en->json('data.tests'))->firstWhere('code', 'theory');
        $this->assertNotNull($theoryEn);
        $this->assertStringContainsString('before booking', (string) $theoryEn['reason']);
        $this->assertStringNotContainsString(':previous_test', (string) $theoryEn['reason']);
        $this->assertStringNotContainsString('يجب', (string) $theoryEn['reason']);
        $this->assertNoMessageKeyLeak($en->getContent());
    }

    public function test_license_and_fine_messages_are_bilingual(): void
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-LIC-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => now(),
            'issued_at' => now(),
        ]);

        License::query()->create([
            'license_number' => 'LIC-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->toDateString(),
            'expiry_date' => now()->addYears(5)->toDateString(),
        ]);

        Fine::query()->create([
            'citizen_id' => $citizen->id,
            'amount' => 1000,
            'reason' => 'Test fine',
            'status' => FineStatus::Unpaid,
        ]);

        Sanctum::actingAs($citizen);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/licenses')
            ->assertOk()
            ->assertJsonPath('message', 'تم جلب رخص القيادة بنجاح.');

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/fines')
            ->assertOk()
            ->assertJsonPath('message', 'تم جلب الغرامات بنجاح.');

        $enLicenses = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/licenses')
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Driving licenses retrieved successfully.');

        $enFines = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/fines')
            ->assertOk()
            ->assertJsonPath('message', 'Fines retrieved successfully.');

        $this->assertNoMessageKeyLeak($enLicenses->getContent());
        $this->assertNoMessageKeyLeak($enFines->getContent());
    }

    public function test_middleware_access_errors_are_bilingual(): void
    {
        $employee = User::factory()->dashboardEmployee('employee')->create([
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($employee);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/applications')
            ->assertForbidden()
            ->assertJsonPath('message', 'هذه الخدمة متاحة للمواطنين فقط.');

        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/applications')
            ->assertForbidden()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'This service is available to citizens only.');

        $inactive = $this->citizen(['is_active' => false]);
        Sanctum::actingAs($inactive);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/applications')
            ->assertForbidden()
            ->assertJsonPath('message', 'الحساب غير فعال.');

        $enInactive = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/applications')
            ->assertForbidden()
            ->assertJsonPath('message', 'This account is inactive.');

        $this->assertNoMessageKeyLeak($enInactive->getContent());
    }

    public function test_validation_failure_is_bilingual(): void
    {
        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', 'فشل التحقق من البيانات المدخلة.')
            ->assertJsonPath('errors.password.0', 'حقل كلمة المرور مطلوب.');

        $en = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'The submitted data failed validation.');

        $passwordError = (string) $en->json('errors.password.0');
        $this->assertStringContainsString('password', strtolower($passwordError));
        $this->assertStringNotContainsString('كلمة المرور', $passwordError);
        $this->assertNoMessageKeyLeak($en->getContent());
    }

    public function test_dashboard_and_ai_remain_isolated_from_english_citizen_pack(): void
    {
        $employee = User::factory()->dashboardEmployee()->create([
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/dashboard/auth/login', [
                'email' => $employee->email,
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'تم تسجيل الدخول بنجاح.');

        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $session = \App\Modules\AIAgent\Models\AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => 'active',
            'current_intent' => 'general_help',
            'context' => [],
        ]);

        $action = \App\Modules\AIAgent\Models\AIAgentAction::query()->create([
            'session_id' => $session->id,
            'user_id' => $citizen->id,
            'action_name' => 'create_application',
            'arguments' => [],
            'status' => \App\Modules\AIAgent\Enums\AgentActionStatus::AwaitingConfirmation,
            'requires_confirmation' => true,
            'confirmation_message' => 'test',
        ]);

        // AI agent conversation replies stay on their own language stack (Arabic default reply).
        $this->withHeader('Accept-Language', 'en')
            ->postJson("/api/ai-agent/actions/{$action->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.reply', 'تم إلغاء العملية. يمكنك طلب المساعدة من جديد في أي وقت.');
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

    /**
     * @return array{0: User, 1: LicenseApplication}
     */
    private function applicationInStatus(ApplicationStatus $status): array
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-BIL-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => $status,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => null,
            'issued_at' => null,
        ]);

        return [$citizen, $application];
    }

    private function assertNoMessageKeyLeak(string $payload): void
    {
        $this->assertStringNotContainsString('messages.', $payload);
    }
}

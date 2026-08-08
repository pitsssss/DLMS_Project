<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\PaymentStatus;
use App\Models\Fee;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\RequiredDocument;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use App\Support\CitizenCatalogLabel;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenCatalogLocalizationTest extends TestCase
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
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_service_type_name_and_description_are_bilingual(): void
    {
        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/service-types')
            ->assertOk();
        $newAr = collect($ar->json('data'))->firstWhere('code', 'new_license');
        $this->assertSame(Lang::get('messages.catalog.service_types.new_license.name', [], 'ar'), $newAr['name']);
        $this->assertSame(Lang::get('messages.catalog.service_types.new_license.description', [], 'ar'), $newAr['description']);

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/service-types')
            ->assertOk();
        $newEn = collect($en->json('data'))->firstWhere('code', 'new_license');
        $this->assertSame(Lang::get('messages.catalog.service_types.new_license.name', [], 'en'), $newEn['name']);
        $this->assertSame(Lang::get('messages.catalog.service_types.new_license.description', [], 'en'), $newEn['description']);
        $this->assertStringNotContainsString('إصدار', json_encode($newEn, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    public function test_required_documents_are_bilingual(): void
    {
        [$citizen, $application] = $this->application(ApplicationStatus::Draft);
        Sanctum::actingAs($citizen);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/applications/{$application->id}/required-documents")
            ->assertOk();
        $nationalAr = collect($ar->json('data'))->firstWhere('code', 'national_id_copy');
        $this->assertSame(Lang::get('messages.catalog.required_documents.national_id_copy', [], 'ar'), $nationalAr['name']);

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/applications/{$application->id}/required-documents")
            ->assertOk();
        $nationalEn = collect($en->json('data'))->firstWhere('code', 'national_id_copy');
        $this->assertSame(Lang::get('messages.catalog.required_documents.national_id_copy', [], 'en'), $nationalEn['name']);
        $this->assertStringNotContainsString('هوية', json_encode($nationalEn, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('messages.', $en->getContent());
        $this->assertSame($nationalAr['code'], $nationalEn['code']);
        $this->assertSame($nationalAr['id'], $nationalEn['id']);
    }

    public function test_fee_names_are_bilingual(): void
    {
        [$citizen, $application] = $this->application(ApplicationStatus::PaymentPending);
        Sanctum::actingAs($citizen);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/applications/{$application->id}/fee")
            ->assertOk();
        $this->assertSame(Lang::get('messages.fees.codes.application_fee', [], 'ar'), $ar->json('data.fee.name'));

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/applications/{$application->id}/fee")
            ->assertOk();
        $this->assertSame(Lang::get('messages.fees.codes.application_fee', [], 'en'), $en->json('data.fee.name'));
        $this->assertSame($ar->json('data.fee.code'), $en->json('data.fee.code'));
        $this->assertSame($ar->json('data.fee.amount'), $en->json('data.fee.amount'));
        $this->assertStringNotContainsString('رسوم', $en->json('data.fee.name'));
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    public function test_application_nested_catalog_labels_are_bilingual(): void
    {
        $vision = TestType::query()->where('code', 'vision')->firstOrFail();
        [$citizen, $application] = $this->application(ApplicationStatus::InTesting, [
            'current_test_type_id' => $vision->id,
        ]);
        Sanctum::actingAs($citizen);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk();
        $this->assertSame(Lang::get('messages.catalog.license_types.private', [], 'ar'), $ar->json('data.license_type.name'));
        $this->assertSame(Lang::get('messages.catalog.service_types.new_license.name', [], 'ar'), $ar->json('data.service_type.name'));
        $this->assertSame(Lang::get('messages.catalog.test_types.vision', [], 'ar'), $ar->json('data.current_test_type.name'));

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/applications/{$application->id}")
            ->assertOk();
        $this->assertSame(Lang::get('messages.catalog.license_types.private', [], 'en'), $en->json('data.license_type.name'));
        $this->assertSame(Lang::get('messages.catalog.service_types.new_license.name', [], 'en'), $en->json('data.service_type.name'));
        $this->assertSame(Lang::get('messages.catalog.test_types.vision', [], 'en'), $en->json('data.current_test_type.name'));
        $this->assertSame($ar->json('data.license_type.code'), $en->json('data.license_type.code'));
        $this->assertSame($ar->json('data.service_type.code'), $en->json('data.service_type.code'));
        $this->assertStringNotContainsString('خصوصي', $en->getContent());
        $this->assertStringNotContainsString('إصدار', $en->getContent());
        $this->assertStringNotContainsString('فحص', $en->getContent());
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    public function test_available_tests_and_appointment_result_labels_are_bilingual(): void
    {
        [$citizen, $application] = $this->application(ApplicationStatus::AppointmentPending);
        Sanctum::actingAs($citizen);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk();
        $visionAr = collect($ar->json('data.tests'))->firstWhere('code', 'vision');
        $this->assertNotNull($visionAr);
        $this->assertSame(Lang::get('messages.catalog.test_types.vision', [], 'ar'), $visionAr['name']);

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk();
        $visionEn = collect($en->json('data.tests'))->firstWhere('code', 'vision');
        $this->assertSame(Lang::get('messages.catalog.test_types.vision', [], 'en'), $visionEn['name']);
        $this->assertStringNotContainsString('فحص', $visionEn['name']);
        $this->assertStringNotContainsString('messages.', $en->getContent());

        $vision = TestType::query()->where('code', 'vision')->firstOrFail();
        app()->setLocale('en');

        $appointmentPayload = (new \App\Modules\Appointments\Resources\TestAppointmentResource(
            new TestAppointment([
                'id' => 1,
                'application_id' => $application->id,
                'test_type_id' => $vision->id,
                'status' => 'booked',
                'scheduled_at' => now(),
                'cancellation_reason' => 'Citizen free-text cancel reason',
            ])->setRelation('testType', $vision)
        ))->resolve();

        $this->assertSame(Lang::get('messages.catalog.test_types.vision', [], 'en'), $appointmentPayload['test_type']['name']);
        $this->assertSame('Citizen free-text cancel reason', $appointmentPayload['cancellation_reason']);

        $resultPayload = (new \App\Modules\Tests\Resources\TestResultResource(
            new TestResult([
                'id' => 1,
                'application_id' => $application->id,
                'test_type_id' => $vision->id,
                'result' => 'passed',
                'attempt_number' => 1,
                'notes' => 'Free-text notes stay raw',
                'recorded_at' => now(),
            ])->setRelation('testType', $vision)
        ))->resolve();

        $this->assertSame(Lang::get('messages.catalog.test_types.vision', [], 'en'), $resultPayload['test_type']['name']);
        $this->assertSame('Free-text notes stay raw', $resultPayload['notes']);
    }

    public function test_unknown_code_falls_back_to_db_name(): void
    {
        $custom = ServiceType::query()->create([
            'name' => 'خدمة مخصصة إدارية',
            'code' => 'custom_admin_service_x',
            'description' => 'وصف مخصص',
            'is_active' => true,
        ]);

        app()->setLocale('en');
        $this->assertSame('خدمة مخصصة إدارية', CitizenCatalogLabel::serviceType($custom->code, $custom->name));
        $this->assertSame('وصف مخصص', CitizenCatalogLabel::serviceTypeDescription($custom->code, $custom->description));

        $doc = RequiredDocument::query()->create([
            'name' => 'وثيقة مخصصة',
            'code' => 'custom_doc_xyz',
            'is_required' => true,
            'is_active' => true,
            'allowed_extensions' => ['pdf'],
            'max_size_kb' => 1024,
            'license_type_id' => null,
            'service_type_id' => null,
        ]);
        $this->assertSame('وثيقة مخصصة', CitizenCatalogLabel::requiredDocument($doc->code, $doc->name));

        $this->assertSame('custom_unknown_fee', CitizenCatalogLabel::fee('custom_unknown_fee', null));
        $this->assertSame('DB Fee Name', CitizenCatalogLabel::fee('custom_unknown_fee', 'DB Fee Name'));
    }

    public function test_known_code_ignores_admin_edited_db_name(): void
    {
        $service = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $service->update([
            'name' => 'اسم عربي معدّل من الإدارة',
            'description' => 'وصف معدّل',
        ]);

        app()->setLocale('en');
        $this->assertSame(
            Lang::get('messages.catalog.service_types.new_license.name', [], 'en'),
            CitizenCatalogLabel::serviceType('new_license', $service->fresh()->name)
        );

        $fee = Fee::query()->where('code', 'application_fee')->firstOrFail();
        $fee->update(['name' => 'رسوم معدلة إدارياً']);
        $this->assertSame(
            Lang::get('messages.fees.codes.application_fee', [], 'en'),
            CitizenCatalogLabel::fee('application_fee', $fee->fresh()->name)
        );
    }

    public function test_payment_resource_fee_name_is_localized(): void
    {
        [$citizen, $application] = $this->application(ApplicationStatus::PaymentPending);
        $fee = Fee::query()->where('code', 'application_fee')->firstOrFail();
        $key = Payment::obligationKey($application->id, $fee->id);

        Payment::query()->create([
            'payment_number' => 'PAY-CAT-'.strtoupper(Str::random(6)),
            'user_id' => $citizen->id,
            'application_id' => $application->id,
            'fine_id' => null,
            'fee_id' => $fee->id,
            'amount' => $fee->amount,
            'currency' => $fee->currency,
            'status' => PaymentStatus::Pending,
            'provider' => 'mock',
            'active_obligation_key' => $key,
            'metadata' => [],
        ]);

        Sanctum::actingAs($citizen);

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/applications/{$application->id}/payments")
            ->assertOk();

        $feeName = $en->json('data.0.fee.name');
        $this->assertSame(Lang::get('messages.fees.codes.application_fee', [], 'en'), $feeName);
        $this->assertStringNotContainsString('رسوم', (string) $feeName);
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: LicenseApplication}
     */
    private function application(ApplicationStatus $status, array $overrides = []): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create(array_merge([
            'application_number' => 'APP-CAT-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => $status,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => null,
            'issued_at' => null,
        ], $overrides));

        return [$citizen, $application];
    }
}

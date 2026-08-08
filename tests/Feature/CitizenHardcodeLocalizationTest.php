<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\LicenseStatus;
use App\Models\AppointmentSlot;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Models\User;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenHardcodeLocalizationTest extends TestCase
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
            AppointmentSlotsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_license_eligibility_failures_are_bilingual(): void
    {
        $owner = $this->citizen();
        $other = $this->citizen();
        $license = $this->activeLicenseFor($owner, expiryDays: 400);

        Sanctum::actingAs($other);

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/applications', [
                'service_type_code' => 'renew_license',
                'related_license_id' => $license->id,
            ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'الرخصة المحددة لا تخصك.');

        // Ownership is checked before eligibility; force eligibility via status.
        Sanctum::actingAs($owner);
        $license->update(['status' => LicenseStatus::Suspended]);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/applications', [
                'service_type_code' => 'renew_license',
                'related_license_id' => $license->id,
            ])
            ->assertStatus(422);

        $this->assertSame('حالة الرخصة الحالية لا تسمح بتنفيذ هذه الخدمة.', $ar->json('message'));
        $this->assertStringNotContainsString('messages.', $ar->getContent());

        $en = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/applications', [
                'service_type_code' => 'renew_license',
                'related_license_id' => $license->id,
            ])
            ->assertStatus(422);

        $this->assertSame('The current license status does not allow this service.', $en->json('message'));
        $this->assertStringNotContainsString('messages.', $en->getContent());
        $this->assertStringNotContainsString('حالة الرخصة', $en->getContent());
    }

    public function test_renewal_too_early_eligibility_is_bilingual(): void
    {
        $citizen = $this->citizen();
        $license = $this->activeLicenseFor($citizen, expiryDays: 400);
        Sanctum::actingAs($citizen);

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/applications', [
                'service_type_code' => 'renew_license',
                'related_license_id' => $license->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن تجديد الرخصة قبل اقتراب موعد انتهائها.');

        $en = $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/applications', [
                'service_type_code' => 'renew_license',
                'related_license_id' => $license->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'The license cannot be renewed before it is close to expiry.');

        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    public function test_appointment_tests_not_required_is_bilingual(): void
    {
        [$citizen, $application] = $this->applicationForService('lost_replacement', ApplicationStatus::AppointmentPending);
        Sanctum::actingAs($citizen);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk()
            ->assertJsonPath('data.blocked', true)
            ->assertJsonPath('data.message', 'هذه الخدمة لا تتطلب حجز اختبارات.');

        $this->assertStringNotContainsString('messages.', $ar->getContent());

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk()
            ->assertJsonPath('data.blocked', true)
            ->assertJsonPath('data.message', 'This service does not require booking tests.');

        $this->assertStringNotContainsString('messages.', $en->getContent());
        $this->assertStringNotContainsString('هذه الخدمة', $en->getContent());

        $vision = TestType::query()->where('code', 'vision')->firstOrFail();
        $slot = AppointmentSlot::query()
            ->where('test_type_id', $vision->id)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->firstOrFail();

        $this->withHeader('Accept-Language', 'en')
            ->postJson("/api/applications/{$application->id}/appointments", [
                'appointment_slot_id' => $slot->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This service does not require booking tests.');
    }

    public function test_license_type_and_test_type_labels_are_bilingual(): void
    {
        $arLicense = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/license-types')
            ->assertOk();

        $privateAr = collect($arLicense->json('data'))->firstWhere('code', 'private');
        $this->assertSame('خصوصي', $privateAr['name']);

        $enLicense = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/license-types')
            ->assertOk();

        $privateEn = collect($enLicense->json('data'))->firstWhere('code', 'private');
        $this->assertSame('Private', $privateEn['name']);
        $this->assertStringNotContainsString('خصوصي', json_encode($privateEn, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('messages.', $enLicense->getContent());

        $arTests = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/test-types')
            ->assertOk();
        $visionAr = collect($arTests->json('data'))->firstWhere('code', 'vision');
        $this->assertSame('فحص النظر', $visionAr['name']);

        $enTests = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/test-types')
            ->assertOk();
        $visionEn = collect($enTests->json('data'))->firstWhere('code', 'vision');
        $this->assertSame('Vision test', $visionEn['name']);
        $this->assertStringNotContainsString('فحص', json_encode($visionEn, JSON_UNESCAPED_UNICODE));
    }

    public function test_appointment_slot_test_type_labels_are_bilingual(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);
        $vision = TestType::query()->where('code', 'vision')->firstOrFail();

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/appointment-slots?test_type_id='.$vision->id)
            ->assertOk();

        $firstAr = $ar->json('data.0.test_type');
        $this->assertNotNull($firstAr);
        $this->assertSame('vision', $firstAr['code']);
        $this->assertSame('فحص النظر', $firstAr['name']);

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/appointment-slots?test_type_id='.$vision->id)
            ->assertOk();

        $firstEn = $en->json('data.0.test_type');
        $this->assertSame('Vision test', $firstEn['name']);
        $this->assertStringNotContainsString('فحص', json_encode($firstEn, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    public function test_license_status_label_is_bilingual(): void
    {
        $citizen = $this->citizen();
        $this->activeLicenseFor($citizen, expiryDays: 200);
        Sanctum::actingAs($citizen);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/licenses')
            ->assertOk()
            ->assertJsonPath('data.0.status_label', 'فعالة');

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/licenses')
            ->assertOk()
            ->assertJsonPath('data.0.status_label', 'Active')
            ->assertJsonPath('data.0.license_type.name', 'Private');

        $this->assertStringNotContainsString('فعالة', $en->getContent());
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function activeLicenseFor(User $citizen, int $expiryDays): License
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-HC-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now()->subYears(1),
            'approved_at' => now()->subYears(1),
            'issued_at' => now()->subYears(1),
        ]);

        return License::query()->create([
            'license_number' => 'LIC-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active,
            'issue_date' => now()->subYears(1)->toDateString(),
            'expiry_date' => now()->addDays($expiryDays)->toDateString(),
        ]);
    }

    /**
     * @return array{0: User, 1: LicenseApplication}
     */
    private function applicationForService(string $serviceCode, ApplicationStatus $status): array
    {
        $citizen = $this->citizen();
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', $serviceCode)->firstOrFail();
        $related = $this->activeLicenseFor($citizen, expiryDays: 200);

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-SVC-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'related_license_id' => $related->id,
            'status' => $status,
            'submitted_at' => now(),
            'approved_at' => null,
            'issued_at' => null,
        ]);

        return [$citizen, $application];
    }
}

<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\LicenseStatus;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\User;
use App\Support\Msg;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

class LicenseVerificationLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_valid_verification_is_bilingual(): void
    {
        $license = $this->createVerifiableLicense(LicenseStatus::Active, now()->addYears(2)->toDateString());

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/licenses/verify/'.$license->verification_token)
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('message', 'تم التحقق من الرخصة.')
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_label', 'فعالة')
            ->assertJsonPath('data.message', 'الرخصة سارية وصحيحة.')
            ->assertJsonPath('data.license_type.code', 'private')
            ->assertJsonPath('data.license_type.label', 'خصوصي');

        $this->assertSame($license->license_number, $ar->json('data.license_number'));
        $this->assertStringNotContainsString('messages.', $ar->getContent());

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/licenses/verify/'.$license->verification_token)
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'License verified.')
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_label', 'Active')
            ->assertJsonPath('data.message', 'The license is valid.')
            ->assertJsonPath('data.license_type.label', 'Private');

        $this->assertSame($license->license_number, $en->json('data.license_number'));
        $this->assertStringNotContainsString('messages.', $en->getContent());
        $this->assertStringNotContainsString('فعالة', $en->getContent());
        $this->assertStringNotContainsString('خصوصي', $en->getContent());
        $this->assertStringNotContainsString('الرخصة سارية', $en->getContent());
    }

    public function test_invalid_status_verification_is_bilingual(): void
    {
        $license = $this->createVerifiableLicense(LicenseStatus::Blocked, now()->addYear()->toDateString());

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/licenses/verify/'.$license->verification_token)
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.status_label', 'محظورة')
            ->assertJsonPath('data.message', 'الرخصة غير سارية حالياً.');

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/licenses/verify/'.$license->verification_token)
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.status_label', 'Blocked')
            ->assertJsonPath('data.message', 'The license is not currently valid.');

        $this->assertStringNotContainsString('messages.', $en->getContent());
        $this->assertStringNotContainsString('محظورة', $en->getContent());
    }

    public function test_not_found_verification_is_bilingual(): void
    {
        $token = str_repeat('z', 40);

        $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/licenses/verify/'.$token)
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.license_number', null)
            ->assertJsonPath('data.message', 'تعذر التحقق من الرخصة.');

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/licenses/verify/'.$token)
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.license_number', null)
            ->assertJsonPath('data.message', 'Unable to verify the license.');

        $this->assertSame(200, $en->status());
        $this->assertStringNotContainsString('messages.', $en->getContent());
        $this->assertStringNotContainsString('تعذر', $en->getContent());
    }

    public function test_short_token_and_unknown_token_share_not_found_message(): void
    {
        $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/licenses/verify/short')
            ->assertOk()
            ->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.message', 'Unable to verify the license.');
    }

    public function test_dashboard_license_status_labels_remain_arabic_when_app_locale_is_english(): void
    {
        app()->setLocale('en');

        $this->assertSame('فعالة', Msg::get('licenses.statuses.active'));
        $this->assertSame('محظورة', Msg::get('licenses.statuses.blocked'));
    }

    private function createVerifiableLicense(LicenseStatus $status, string $expiryDate): License
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'name' => 'Verification Holder',
        ]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-VL-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::LicenseIssued,
            'submitted_at' => now()->subMonth(),
            'approved_at' => now()->subMonth(),
            'issued_at' => now()->subMonth(),
        ]);

        return License::query()->create([
            'license_number' => 'LIC-'.strtoupper(Str::random(8)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'application_id' => $application->id,
            'status' => $status,
            'issue_date' => now()->subMonth()->toDateString(),
            'expiry_date' => $expiryDate,
            'verification_token' => Str::random(40),
        ]);
    }
}

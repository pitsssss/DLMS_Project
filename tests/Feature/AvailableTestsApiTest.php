<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\TestResultStatus;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Appointments\Support\AvailableTestReasonCode;
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

class AvailableTestsApiTest extends TestCase
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

    /**
     * @return array{0: User, 1: LicenseApplication}
     */
    private function createLicenseApplication(ApplicationStatus $status): array
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-AVT-'.strtoupper(Str::random(6)),
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

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAvailableTests(User $citizen, LicenseApplication $application): array
    {
        return $this->fetchAvailableTestsResponse($citizen, $application)['data']['tests'];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAvailableTestsResponse(User $citizen, LicenseApplication $application): array
    {
        Sanctum::actingAs($citizen);

        return $this->getJson("/api/applications/{$application->id}/available-tests")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json();
    }

    /**
     * @param  list<array<string, mixed>>  $tests
     * @return array<string, mixed>
     */
    private function testByCode(array $tests, string $code): array
    {
        $item = collect($tests)->firstWhere('code', $code);
        $this->assertNotNull($item, "Missing test code: {$code}");

        return $item;
    }

    /**
     * @param  list<array<string, mixed>>  $tests
     */
    private function assertAvailabilityFieldsPresent(array $tests): void
    {
        foreach ($tests as $test) {
            $this->assertArrayHasKey('is_available', $test);
            $this->assertArrayHasKey('reason_code', $test);
            $this->assertArrayHasKey('reason', $test);
            $this->assertArrayHasKey('next_action_label', $test);
        }
    }

    private function visionSlot(): AppointmentSlot
    {
        $vision = TestType::query()->where('code', 'vision')->firstOrFail();

        return AppointmentSlot::query()
            ->where('test_type_id', $vision->id)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->firstOrFail();
    }

    private function employeeUser(): User
    {
        return User::factory()->dashboardEmployee('employee')->create();
    }

    private function recordPassedResult(User $citizen, LicenseApplication $application, string $testCode): void
    {
        Sanctum::actingAs($citizen);
        $appointmentId = (int) TestAppointment::query()
            ->where('application_id', $application->id)
            ->whereHas('testType', fn ($q) => $q->where('code', $testCode))
            ->latest('id')
            ->value('id');

        Sanctum::actingAs($this->employeeUser());
        $this->postJson("/api/admin/test-appointments/{$appointmentId}/record-result", [
            'result' => TestResultStatus::Passed->value,
        ])->assertOk();
    }

    public function test_appointment_pending_application_marks_first_test_available_with_reasons_for_later_tests(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AppointmentPending);
        $tests = $this->fetchAvailableTests($citizen, $application);

        $vision = $this->testByCode($tests, 'vision');
        $theory = $this->testByCode($tests, 'theory');
        $practical = $this->testByCode($tests, 'practical');

        $this->assertTrue($vision['is_available']);
        $this->assertTrue($vision['can_book']);
        $this->assertNull($vision['reason_code']);
        $this->assertNull($vision['reason']);
        $this->assertEquals('حجز موعد', $vision['next_action_label']);

        $this->assertFalse($theory['is_available']);
        $this->assertEquals(AvailableTestReasonCode::PREVIOUS_TEST_NOT_PASSED, $theory['reason_code']);
        $this->assertStringContainsString('اختبار النظر', (string) $theory['reason']);
        $this->assertStringContainsString('الاختبار النظري', (string) $theory['reason']);

        $this->assertFalse($practical['is_available']);
        $this->assertContains($practical['reason_code'], [
            AvailableTestReasonCode::PREVIOUS_TEST_NOT_PASSED,
            AvailableTestReasonCode::NOT_CURRENT_TEST,
        ]);
        $this->assertNotEmpty($practical['reason']);
    }

    public function test_payment_pending_application_marks_all_tests_unavailable_with_payment_reason(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::PaymentPending);
        $tests = $this->fetchAvailableTests($citizen, $application);

        foreach ($tests as $test) {
            $this->assertFalse($test['is_available']);
            $this->assertEquals(AvailableTestReasonCode::PAYMENT_NOT_COMPLETED, $test['reason_code']);
            $this->assertStringContainsString('الدفع', (string) $test['reason']);
            $this->assertNull($test['next_action_label']);
        }
    }

    public function test_draft_application_marks_all_tests_unavailable_with_not_ready_reason(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::Draft);
        $tests = $this->fetchAvailableTests($citizen, $application);

        foreach ($tests as $test) {
            $this->assertFalse($test['is_available']);
            $this->assertEquals(AvailableTestReasonCode::APPLICATION_NOT_READY_FOR_TESTING, $test['reason_code']);
            $this->assertStringContainsString('مرحلة الاختبارات', (string) $test['reason']);
        }
    }

    public function test_after_vision_passed_theory_becomes_available(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AppointmentPending);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $this->visionSlot()->id,
        ])->assertOk();

        $this->recordPassedResult($citizen, $application, 'vision');
        $application->refresh();

        $tests = $this->fetchAvailableTests($citizen, $application);
        $vision = $this->testByCode($tests, 'vision');
        $theory = $this->testByCode($tests, 'theory');
        $practical = $this->testByCode($tests, 'practical');

        $this->assertFalse($vision['is_available']);
        $this->assertTrue($vision['is_completed']);
        $this->assertEquals(AvailableTestReasonCode::ALREADY_PASSED, $vision['reason_code']);

        $this->assertTrue($theory['is_available']);
        $this->assertNull($theory['reason_code']);

        $this->assertFalse($practical['is_available']);
        $this->assertEquals(AvailableTestReasonCode::PREVIOUS_TEST_NOT_PASSED, $practical['reason_code']);
        $this->assertStringContainsString('الاختبار النظري', (string) $practical['reason']);
    }

    public function test_after_theory_passed_practical_becomes_available(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AppointmentPending);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $this->visionSlot()->id,
        ])->assertOk();
        $this->recordPassedResult($citizen, $application, 'vision');

        $theory = TestType::query()->where('code', 'theory')->firstOrFail();
        $theorySlot = AppointmentSlot::query()
            ->where('test_type_id', $theory->id)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->firstOrFail();

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $theorySlot->id,
        ])->assertOk();
        $this->recordPassedResult($citizen, $application, 'theory');

        $tests = $this->fetchAvailableTests($citizen, $application);

        $this->assertFalse($this->testByCode($tests, 'vision')['is_available']);
        $this->assertFalse($this->testByCode($tests, 'theory')['is_available']);
        $this->assertEquals(AvailableTestReasonCode::ALREADY_PASSED, $this->testByCode($tests, 'vision')['reason_code']);
        $this->assertEquals(AvailableTestReasonCode::ALREADY_PASSED, $this->testByCode($tests, 'theory')['reason_code']);

        $practical = $this->testByCode($tests, 'practical');
        $this->assertTrue($practical['is_available']);
        $this->assertNull($practical['reason_code']);
        $this->assertEquals('حجز موعد', $practical['next_action_label']);
    }

    public function test_active_vision_appointment_returns_active_appointment_reason(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AppointmentPending);

        Sanctum::actingAs($citizen);
        $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $this->visionSlot()->id,
        ])->assertOk();

        TestAppointment::query()
            ->where('application_id', $application->id)
            ->where('status', AppointmentStatus::Booked)
            ->update(['scheduled_at' => now()->addDay()]);

        $tests = $this->fetchAvailableTests($citizen, $application);
        $vision = $this->testByCode($tests, 'vision');

        $this->assertFalse($vision['is_available']);
        $this->assertTrue($vision['has_active_appointment']);
        $this->assertEquals(AvailableTestReasonCode::ACTIVE_APPOINTMENT_EXISTS, $vision['reason_code']);
        $this->assertStringContainsString('موعد فعال', (string) $vision['reason']);
    }

    public function test_past_booked_appointment_without_result_returns_waiting_result_reason(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::InTesting);

        $vision = TestType::query()->where('code', 'vision')->firstOrFail();
        $slot = $this->visionSlot();

        TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $vision->id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => now()->subHour(),
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);

        $tests = $this->fetchAvailableTests($citizen, $application);
        $visionItem = $this->testByCode($tests, 'vision');

        $this->assertFalse($visionItem['is_available']);
        $this->assertEquals(AvailableTestReasonCode::WAITING_RESULT, $visionItem['reason_code']);
        $this->assertStringContainsString('تسجيل النتيجة', (string) $visionItem['reason']);
    }

    public function test_waiting_retest_allows_failed_test_and_blocks_later_tests(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AppointmentPending);

        Sanctum::actingAs($citizen);
        $appointmentId = (int) $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $this->visionSlot()->id,
        ])->json('data.id');

        Sanctum::actingAs($this->employeeUser());
        $this->postJson("/api/admin/test-appointments/{$appointmentId}/record-result", [
            'result' => TestResultStatus::Passed->value,
        ])->assertOk();

        $theory = TestType::query()->where('code', 'theory')->firstOrFail();
        $theorySlot = AppointmentSlot::query()
            ->where('test_type_id', $theory->id)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->firstOrFail();

        Sanctum::actingAs($citizen);
        $theoryAppointmentId = (int) $this->postJson("/api/applications/{$application->id}/appointments", [
            'appointment_slot_id' => $theorySlot->id,
        ])->json('data.id');

        Sanctum::actingAs($this->employeeUser());
        $this->postJson("/api/admin/test-appointments/{$theoryAppointmentId}/record-result", [
            'result' => TestResultStatus::Failed->value,
        ])->assertOk();

        $application->refresh();
        $this->assertEquals(ApplicationStatus::WaitingRetest, $application->status);

        $tests = $this->fetchAvailableTests($citizen, $application);
        $vision = $this->testByCode($tests, 'vision');
        $theoryItem = $this->testByCode($tests, 'theory');
        $practical = $this->testByCode($tests, 'practical');

        $this->assertFalse($vision['is_available']);
        $this->assertEquals(AvailableTestReasonCode::ALREADY_PASSED, $vision['reason_code']);

        $this->assertTrue($theoryItem['is_available']);
        $this->assertNull($theoryItem['reason_code']);

        $this->assertFalse($practical['is_available']);
        $this->assertEquals(AvailableTestReasonCode::PREVIOUS_TEST_NOT_PASSED, $practical['reason_code']);
    }

    public function test_max_attempts_reached_returns_max_attempts_reason(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AdministrativeReview);
        $vision = TestType::query()->where('code', 'vision')->firstOrFail();
        $slot = $this->visionSlot();

        $application->update(['current_test_type_id' => $vision->id]);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $appointment = TestAppointment::query()->create([
                'application_id' => $application->id,
                'citizen_id' => $citizen->id,
                'appointment_slot_id' => $slot->id,
                'test_type_id' => $vision->id,
                'status' => AppointmentStatus::Completed,
                'scheduled_at' => now()->subDays($attempt),
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ]);

            TestResult::query()->create([
                'application_id' => $application->id,
                'test_appointment_id' => $appointment->id,
                'test_type_id' => $vision->id,
                'result' => TestResultStatus::Failed,
                'attempt_number' => $attempt,
                'notes' => null,
                'recorded_by' => $this->employeeUser()->id,
                'recorded_at' => now(),
            ]);
        }

        $tests = $this->fetchAvailableTests($citizen, $application);
        $visionItem = $this->testByCode($tests, 'vision');

        $this->assertFalse($visionItem['is_available']);
        $this->assertEquals(AvailableTestReasonCode::MAX_ATTEMPTS_REACHED, $visionItem['reason_code']);
        $this->assertStringContainsString('الحد الأقصى', (string) $visionItem['reason']);
        $this->assertEquals(3, $visionItem['attempts_count']);
    }

    public function test_approved_application_marks_tests_unavailable(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::Approved);
        $tests = $this->fetchAvailableTests($citizen, $application);

        foreach ($tests as $test) {
            $this->assertFalse($test['is_available']);
            $this->assertContains($test['reason_code'], [
                AvailableTestReasonCode::APPLICATION_ALREADY_APPROVED,
                AvailableTestReasonCode::ALREADY_PASSED,
            ]);
            $this->assertNotEmpty($test['reason']);
        }
    }

    public function test_license_issued_application_marks_tests_unavailable(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::LicenseIssued);
        $tests = $this->fetchAvailableTests($citizen, $application);

        foreach ($tests as $test) {
            $this->assertFalse($test['is_available']);
            $this->assertEquals(AvailableTestReasonCode::LICENSE_ALREADY_ISSUED, $test['reason_code']);
            $this->assertStringContainsString('إصدار الرخصة', (string) $test['reason']);
        }
    }

    public function test_response_always_includes_availability_fields_without_translation_keys(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AppointmentPending);
        $response = $this->fetchAvailableTestsResponse($citizen, $application);
        $tests = $response['data']['tests'];

        $this->assertAvailabilityFieldsPresent($tests);
        $this->assertStringNotContainsString('messages.', json_encode($response, JSON_UNESCAPED_UNICODE));

        foreach ($tests as $test) {
            if (! $test['is_available']) {
                $this->assertNotNull($test['reason_code']);
                $this->assertNotNull($test['reason']);
                $this->assertStringNotContainsString('messages.', (string) $test['reason']);
                $this->assertMatchesRegularExpression('/^[a-z_]+$/', (string) $test['reason_code']);
            } else {
                $this->assertNull($test['reason_code']);
                $this->assertNull($test['reason']);
                $this->assertEquals('حجز موعد', $test['next_action_label']);
            }
        }
    }

    public function test_available_tests_top_level_message_is_arabic(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AppointmentPending);
        $response = $this->fetchAvailableTestsResponse($citizen, $application);

        $this->assertSame('تم جلب الاختبارات المتاحة بنجاح.', $response['message']);
    }

    public function test_unavailable_test_reason_uses_previous_test_names_in_arabic(): void
    {
        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AppointmentPending);
        $tests = $this->fetchAvailableTests($citizen, $application);
        $theory = $this->testByCode($tests, 'theory');

        $this->assertEquals(AvailableTestReasonCode::PREVIOUS_TEST_NOT_PASSED, $theory['reason_code']);
        $this->assertSame(
            'يجب اجتياز اختبار النظر أولاً قبل حجز الاختبار النظري.',
            $theory['reason']
        );
    }

    public function test_available_tests_returns_arabic_user_fields_when_app_locale_is_english(): void
    {
        app()->setLocale('en');

        [$citizen, $application] = $this->createLicenseApplication(ApplicationStatus::AppointmentPending);
        $response = $this->fetchAvailableTestsResponse($citizen, $application);
        $tests = $response['data']['tests'];

        $this->assertSame('تم جلب الاختبارات المتاحة بنجاح.', $response['message']);
        $this->assertStringNotContainsString('messages.', json_encode($response, JSON_UNESCAPED_UNICODE));

        $vision = $this->testByCode($tests, 'vision');
        $theory = $this->testByCode($tests, 'theory');

        $this->assertTrue($vision['is_available']);
        $this->assertSame('حجز موعد', $vision['next_action_label']);
        $this->assertFalse($theory['is_available']);
        $this->assertSame(AvailableTestReasonCode::PREVIOUS_TEST_NOT_PASSED, $theory['reason_code']);
        $this->assertSame(
            'يجب اجتياز اختبار النظر أولاً قبل حجز الاختبار النظري.',
            $theory['reason']
        );
    }
}

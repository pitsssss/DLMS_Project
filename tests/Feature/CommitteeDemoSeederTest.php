<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\LicenseStatus;
use App\Enums\TestResultStatus;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\TestAppointment;
use App\Models\User;
use App\Modules\Licenses\Services\LicenseIssuanceEligibilityService;
use Database\Seeders\CommitteeDemoSeeder;
use Database\Seeders\Support\CommitteeDemoKit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class CommitteeDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_committee_demo_seeder_refuses_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local or testing');

        (new CommitteeDemoSeeder())->run();
    }

    public function test_committee_demo_end_to_end_via_real_http_endpoints(): void
    {
        $this->seed(CommitteeDemoSeeder::class);

        $examiner = User::query()->where('email', CommitteeDemoKit::EXAMINER_EMAIL)->firstOrFail();
        $issuer = User::query()->where('email', CommitteeDemoKit::ISSUER_EMAIL)->firstOrFail();

        $this->assertTrue($examiner->hasPermission('record_test_result'));
        $this->assertTrue($examiner->hasPermission('access_dashboard'));
        $this->assertTrue($issuer->hasPermission('issue_license'));
        $this->assertTrue($issuer->hasPermission('view_applications'));
        $this->assertTrue($issuer->hasPermission('view_licenses'));

        $appA = $this->application(CommitteeDemoKit::APP_A);
        $appB = $this->application(CommitteeDemoKit::APP_B);
        $appC = $this->application(CommitteeDemoKit::APP_C);
        $appD = $this->application(CommitteeDemoKit::APP_D);

        $appointmentA = $this->waitingAppointment($appA);
        $appointmentB = $this->waitingAppointment($appB);
        $appointmentC = $this->waitingAppointment($appC);

        $this->assertSame(AppointmentStatus::Booked, $appointmentA->status);
        $this->assertSame('practical', $appointmentA->testType?->code);
        $this->assertSame(ApplicationStatus::InTesting, $appA->status);

        Sanctum::actingAs($examiner);

        $queue = $this->getJson('/api/dashboard/test-appointments')->assertOk();
        $ids = collect($queue->json('data.items'))->pluck('id')->all();
        $this->assertContains($appointmentA->id, $ids);
        $this->assertContains($appointmentB->id, $ids);
        $this->assertContains($appointmentC->id, $ids);

        $this->getJson('/api/dashboard/test-appointments?search='.CommitteeDemoKit::APP_A)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $appointmentA->id)
            ->assertJsonPath('data.items.0.actions.can_record_result', true)
            ->assertJsonPath('data.items.0.test_type.code', 'practical');

        $this->postJson("/api/admin/test-appointments/{$appointmentA->id}/record-result", [
            'result' => TestResultStatus::Passed->value,
            'notes' => 'نجاح الاختبار العملي - بيانات تجريبية للجنة',
        ])->assertOk()
            ->assertJsonPath('data.result', TestResultStatus::Passed->value);

        $appA->refresh();
        $this->assertSame(ApplicationStatus::Approved, $appA->status);
        $this->assertTrue(app(LicenseIssuanceEligibilityService::class)->isReady($appA));

        Sanctum::actingAs($issuer);

        $this->getJson('/api/dashboard/license-issuance/applications?search='.CommitteeDemoKit::APP_A)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $appA->id)
            ->assertJsonPath('data.items.0.readiness.is_ready', true)
            ->assertJsonPath('data.items.0.actions.can_issue_license', true);

        $issued = $this->postJson("/api/admin/applications/{$appA->id}/issue-license")
            ->assertOk()
            ->assertJsonPath('data.status', LicenseStatus::Active->value);

        $this->assertNotNull($issued->json('data.id'));
        $this->assertSame(ApplicationStatus::LicenseIssued, $appA->fresh()->status);
        $this->assertTrue(License::query()->where('application_id', $appA->id)->exists());

        $this->getJson('/api/dashboard/licenses/'.$issued->json('data.id'))
            ->assertOk()
            ->assertJsonPath('data.id', $issued->json('data.id'));

        Sanctum::actingAs($examiner);

        $this->postJson("/api/admin/test-appointments/{$appointmentB->id}/record-result", [
            'result' => TestResultStatus::Failed->value,
        ])->assertOk();
        $this->assertSame(ApplicationStatus::WaitingRetest, $appB->fresh()->status);

        $this->postJson("/api/admin/test-appointments/{$appointmentC->id}/record-result", [
            'result' => TestResultStatus::NoShow->value,
        ])->assertOk();
        $this->assertSame(ApplicationStatus::WaitingRetest, $appC->fresh()->status);
        $this->assertSame(AppointmentStatus::NoShow, $appointmentC->fresh()->status);

        Sanctum::actingAs($issuer);

        $this->getJson('/api/dashboard/license-issuance/applications?search='.CommitteeDemoKit::APP_D)
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $appD->id)
            ->assertJsonPath('data.items.0.readiness.is_ready', true)
            ->assertJsonPath('data.items.0.actions.can_issue_license', true);

        $this->assertTrue(app(LicenseIssuanceEligibilityService::class)->isReady($appD->fresh()));
    }

    public function test_committee_demo_seeder_is_idempotent(): void
    {
        $this->seed(CommitteeDemoSeeder::class);
        $firstA = $this->application(CommitteeDemoKit::APP_A)->id;
        $firstCount = LicenseApplication::query()->where('application_number', 'like', 'DEMO-COMMITTEE-%')->count();
        $firstWaiting = TestAppointment::query()
            ->where('status', AppointmentStatus::Booked)
            ->whereHas('application', fn ($q) => $q->where('application_number', 'like', 'DEMO-COMMITTEE-%'))
            ->count();

        $this->seed(CommitteeDemoSeeder::class);

        $this->assertSame($firstA, $this->application(CommitteeDemoKit::APP_A)->id);
        $this->assertSame($firstCount, LicenseApplication::query()->where('application_number', 'like', 'DEMO-COMMITTEE-%')->count());
        $this->assertGreaterThan(0, $firstCount);
        $this->assertSame($firstWaiting, TestAppointment::query()
            ->where('status', AppointmentStatus::Booked)
            ->whereHas('application', fn ($q) => $q->where('application_number', 'like', 'DEMO-COMMITTEE-%'))
            ->count());
        $this->assertGreaterThan(0, $firstWaiting);
        $this->assertSame(ApplicationStatus::Approved, $this->application(CommitteeDemoKit::APP_D)->status);
    }

    private function application(string $number): LicenseApplication
    {
        return LicenseApplication::query()->where('application_number', $number)->firstOrFail();
    }

    private function waitingAppointment(LicenseApplication $application): TestAppointment
    {
        return TestAppointment::query()
            ->with('testType')
            ->where('application_id', $application->id)
            ->where('status', AppointmentStatus::Booked)
            ->whereDoesntHave('testResult')
            ->firstOrFail();
    }
}

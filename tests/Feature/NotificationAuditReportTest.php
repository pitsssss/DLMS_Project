<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\AuditLog;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationAuditReportTest extends TestCase
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
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_ping_reports_phase_nine(): void
    {
        $this->getJson('/api/ping')->assertOk()->assertJsonPath('data.phase', 9);
    }

    public function test_citizen_can_list_and_mark_notifications_read(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        app(NotificationService::class)->sendToUser(
            $citizen->id,
            'Test',
            'Hello citizen',
            'test',
            ['foo' => 'bar']
        );

        Sanctum::actingAs($citizen);

        $list = $this->getJson('/api/notifications')->assertOk();
        $notificationId = (int) $list->json('data.items.0.id');

        $this->putJson("/api/notifications/{$notificationId}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true);
    }

    public function test_document_approval_creates_audit_log_and_notification(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-N-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'issued_at' => null,
        ]);

        app(\App\Modules\Applications\Repositories\ApplicationRepository::class)->transitionStatus(
            $application,
            ApplicationStatus::PaymentPending,
            null,
            'Test transition'
        );

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'license_application',
            'entity_id' => $application->id,
            'action' => 'application.status_changed',
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $citizen->id,
            'type' => 'application.payment_pending',
        ]);
    }

    public function test_admin_can_view_audit_logs_and_reports(): void
    {
        AuditLog::query()->create([
            'user_id' => null,
            'action' => 'test.action',
            'entity_type' => 'test',
            'entity_id' => 1,
            'old_values' => null,
            'new_values' => ['ok' => true],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
        ]);

        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'admin')->value('id'),
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.items.0.action', 'test.action');

        $this->getJson('/api/admin/reports/overview')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'applications',
                    'licenses',
                    'payments',
                    'fines',
                    'appointments',
                    'tests',
                    'generated_at',
                ],
            ]);
    }

    public function test_admin_can_view_application_status_history(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-H-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::Draft,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'issued_at' => null,
        ]);

        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'admin')->value('id'),
        ]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/application-status-histories/{$application->id}")
            ->assertOk();
    }
}

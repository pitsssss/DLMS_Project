<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeDocumentFile;
use Tests\TestCase;

class DocumentReviewerAuthorizationTest extends TestCase
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
            RequiredDocumentsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function reviewer(): User
    {
        return User::factory()->dashboardEmployee('profile_document_reviewer')->create();
    }

    private function createSubmittedApplication(): int
    {
        Storage::fake('local');
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($citizen);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $applicationId = (int) $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])->assertOk()->json('data.id');

        $checklist = $this->getJson("/api/applications/{$applicationId}/required-documents")->assertOk()->json('data');
        foreach ($checklist as $item) {
            $this->post(
                "/api/applications/{$applicationId}/documents",
                [
                    'required_document_id' => $item['id'],
                    'file' => FakeDocumentFile::pdf('doc-'.$item['code'].'.pdf'),
                ],
                ['Accept' => 'application/json']
            )->assertOk();
        }

        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();

        return $applicationId;
    }

    public function test_document_reviewer_baseline_excludes_view_applications(): void
    {
        $role = Role::query()->where('name', 'profile_document_reviewer')->with('permissions')->firstOrFail();
        $names = $role->permissions->pluck('name')->all();

        $this->assertContains('access_dashboard', $names);
        $this->assertContains('review_documents', $names);
        $this->assertContains('review_profiles', $names);
        $this->assertNotContains('view_applications', $names);
        $this->assertNotContains('manage_applications', $names);
    }

    public function test_reviewer_can_access_document_review_and_not_general_applications(): void
    {
        $applicationId = $this->createSubmittedApplication();
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);

        $this->getJson('/api/dashboard/document-reviews')->assertOk();
        $this->getJson("/api/dashboard/document-reviews/{$applicationId}")->assertOk();

        $this->getJson('/api/dashboard/applications')->assertForbidden();

        $application = LicenseApplication::query()->findOrFail($applicationId);
        $this->getJson('/api/dashboard/applications/'.$application->application_number)->assertForbidden();
    }

    public function test_login_payload_excludes_application_management_permissions(): void
    {
        $reviewer = $this->reviewer();

        $login = $this->postJson('/api/dashboard/auth/login', [
            'email' => $reviewer->email,
            'password' => 'password',
        ])->assertOk();

        $permissions = $login->json('data.user.permissions');
        $this->assertContains('review_documents', $permissions);
        $this->assertNotContains('view_applications', $permissions);
        $this->assertNotContains('manage_applications', $permissions);
        $this->assertFalse($login->json('data.user.is_super_admin'));

        $modules = collect($login->json('data.user.dashboard_modules'))->pluck('key')->all();
        $this->assertContains('document_reviews', $modules);
        $this->assertNotContains('applications', $modules);
    }

    public function test_reviewer_can_approve_document(): void
    {
        $applicationId = $this->createSubmittedApplication();
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);

        $queue = $this->getJson('/api/dashboard/document-reviews')->assertOk();
        $this->assertContains($applicationId, collect($queue->json('data.items'))->pluck('id')->all());

        $this->getJson("/api/dashboard/document-reviews/{$applicationId}")->assertOk();

        $docId = (int) \App\Models\ApplicationDocument::query()
            ->where('application_id', $applicationId)
            ->where('status', \App\Enums\DocumentStatus::PendingReview)
            ->orderBy('id')
            ->value('id');

        $this->assertGreaterThan(0, $docId);
        $this->postJson("/api/dashboard/document-reviews/documents/{$docId}/approve")->assertOk();
    }
}

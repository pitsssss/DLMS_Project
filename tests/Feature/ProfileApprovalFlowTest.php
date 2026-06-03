<?php

namespace Tests\Feature;

use App\Enums\ProfileStatus;
use App\Models\AuditLog;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Services\GeminiAgentClient;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ProfileApprovalFlowTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function citizen(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'email_verified_at' => now(),
        ], $overrides));
    }

    private function reviewer(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', 'employee')->value('id'),
            'email_verified_at' => now(),
            'profile_completed' => true,
            'profile_status' => ProfileStatus::Approved,
        ]);
    }

    private function profilePayload(): array
    {
        return [
            'name' => 'مواطن تجريبي',
            'national_id' => 'NID'.random_int(100000, 999999),
            'birth_date' => '1995-05-10',
            'governorate' => 'دمشق',
            'address' => 'عنوان تجريبي',
        ];
    }

    public function test_citizen_complete_profile_sets_pending_review(): void
    {
        $citizen = $this->citizen();
        Sanctum::actingAs($citizen);

        $response = $this->putJson('/api/profile/complete', $this->profilePayload())
            ->assertOk()
            ->assertJsonPath('message', __('messages.profile.submitted_for_review'))
            ->assertJsonPath('data.profile_completed', true)
            ->assertJsonPath('data.profile_status', ProfileStatus::PendingReview->value);

        $citizen->refresh();
        $this->assertTrue($citizen->profile_completed);
        $this->assertSame(ProfileStatus::PendingReview, $citizen->profileStatus());
        $this->assertNotNull($citizen->profile_submitted_at);
        $this->assertNull($citizen->profile_rejection_reason);
    }

    public function test_citizen_cannot_create_application_while_profile_pending_review(): void
    {
        $citizen = User::factory()->withPendingProfileReview()->create([
            'email_verified_at' => now(),
        ]);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', __('messages.profile.pending_review'));
    }

    public function test_reviewer_can_list_pending_profile_reviews(): void
    {
        User::factory()->withPendingProfileReview()->create(['email_verified_at' => now()]);
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);

        $this->getJson('/api/admin/profile-reviews')
            ->assertOk()
            ->assertJsonPath('message', __('messages.profile.review_list_retrieved'))
            ->assertJsonCount(1, 'data.items');
    }

    public function test_reviewer_can_approve_pending_profile(): void
    {
        $citizen = User::factory()->withPendingProfileReview()->create(['email_verified_at' => now()]);
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);

        $this->postJson("/api/admin/profile-reviews/{$citizen->id}/approve")
            ->assertOk()
            ->assertJsonPath('message', __('messages.profile.approved'))
            ->assertJsonPath('data.profile_status', ProfileStatus::Approved->value);

        $citizen->refresh();
        $this->assertSame(ProfileStatus::Approved, $citizen->profileStatus());
        $this->assertNull($citizen->profile_rejection_reason);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $citizen->id,
            'type' => 'profile.approved',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'profile_approved',
            'entity_type' => 'user',
            'entity_id' => $citizen->id,
        ]);
    }

    public function test_approved_citizen_can_create_application(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])->assertOk();
    }

    public function test_reviewer_can_reject_profile_with_reason(): void
    {
        $citizen = User::factory()->withPendingProfileReview()->create(['email_verified_at' => now()]);
        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);

        $reason = 'الصورة الشخصية غير واضحة';

        $this->postJson("/api/admin/profile-reviews/{$citizen->id}/reject", [
            'rejection_reason' => $reason,
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.profile.rejected'))
            ->assertJsonPath('data.profile_status', ProfileStatus::Rejected->value)
            ->assertJsonPath('data.profile_rejection_reason', $reason);

        $citizen->refresh();
        $this->assertSame(ProfileStatus::Rejected, $citizen->profileStatus());

        $notification = Notification::query()->where('user_id', $citizen->id)->latest('id')->first();
        $this->assertNotNull($notification);
        $this->assertSame('profile.rejected', $notification->type);

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'profile_rejected')
                ->where('entity_id', $citizen->id)
                ->exists()
        );
    }

    public function test_rejected_citizen_cannot_create_application(): void
    {
        $citizen = User::factory()->withRejectedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', __('messages.profile.rejected_blocked'));
    }

    public function test_rejected_citizen_resubmit_moves_to_pending_review(): void
    {
        $citizen = User::factory()->withRejectedProfile()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($citizen);

        $this->putJson('/api/profile/update', [
            'address' => 'عنوان محدث',
        ])
            ->assertOk()
            ->assertJsonPath('message', __('messages.profile.updated_and_submitted'))
            ->assertJsonPath('data.profile_status', ProfileStatus::PendingReview->value);

        $citizen->refresh();
        $this->assertSame(ProfileStatus::PendingReview, $citizen->profileStatus());
        $this->assertNull($citizen->profile_rejection_reason);
    }

    public function test_citizen_cannot_approve_own_profile(): void
    {
        $citizen = User::factory()->withPendingProfileReview()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($citizen);

        $this->postJson("/api/admin/profile-reviews/{$citizen->id}/approve")
            ->assertForbidden();
    }

    public function test_employee_without_review_profiles_permission_cannot_approve(): void
    {
        $citizen = User::factory()->withPendingProfileReview()->create(['email_verified_at' => now()]);

        $role = Role::query()->create(['name' => 'limited_employee']);
        $permission = Permission::query()->where('name', 'review_documents')->firstOrFail();
        $role->permissions()->sync([$permission->id]);

        $employee = User::factory()->create([
            'role_id' => $role->id,
            'email_verified_at' => now(),
            'profile_completed' => true,
            'profile_status' => ProfileStatus::Approved,
        ]);

        Sanctum::actingAs($employee);

        $this->postJson("/api/admin/profile-reviews/{$citizen->id}/approve")
            ->assertForbidden();
    }

    public function test_non_citizen_profile_cannot_be_reviewed(): void
    {
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'admin')->value('id'),
            'email_verified_at' => now(),
            'profile_completed' => true,
            'profile_status' => ProfileStatus::Approved,
        ]);

        $reviewer = $this->reviewer();
        Sanctum::actingAs($reviewer);

        $this->getJson("/api/admin/profile-reviews/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.profile.not_citizen'));
    }

    public function test_profile_status_endpoint_returns_expected_payload(): void
    {
        $citizen = User::factory()->withPendingProfileReview()->create([
            'email_verified_at' => now(),
            'profile_rejection_reason' => null,
        ]);

        Sanctum::actingAs($citizen);

        $this->getJson('/api/profile/status')
            ->assertOk()
            ->assertJsonPath('message', __('messages.profile.status_retrieved'))
            ->assertJsonPath('data.profile_status', ProfileStatus::PendingReview->value)
            ->assertJsonPath('data.profile_completed', true);
    }

    public function test_ai_agent_blocks_create_application_when_profile_pending_review(): void
    {
        $citizen = User::factory()->withPendingProfileReview()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($citizen);

        $mock = Mockery::mock(GeminiAgentClient::class);
        $mock->shouldReceive('generateStructuredResponse')->andReturn([
            'intent' => 'create_new_license_application',
            'confidence' => 0.95,
            'language' => 'ar',
            'reply' => 'ما نوع الرخصة؟',
            'missing_slots' => [],
            'proposed_action' => [
                'name' => 'create_application',
                'arguments' => [
                    'license_type_code' => 'private',
                    'service_type_code' => 'new_license',
                ],
            ],
            'requires_confirmation' => true,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ]);
        $this->instance(GeminiAgentClient::class, $mock);

        $response = $this->postJson('/api/ai-agent/message', [
            'message' => 'بدي رخصة جديدة',
        ])->assertOk();

        $this->assertNull($response->json('data.pending_action'));
        $this->assertStringContainsString(
            'قيد المراجعة',
            (string) $response->json('data.reply')
        );
    }

    public function test_ai_agent_confirm_create_application_fails_when_profile_not_approved(): void
    {
        $citizen = User::factory()->withPendingProfileReview()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($citizen);

        $session = AIAgentSession::query()->create([
            'user_id' => $citizen->id,
            'status' => 'active',
            'current_intent' => 'create_new_license_application',
            'context' => [],
        ]);

        $action = AIAgentAction::query()->create([
            'session_id' => $session->id,
            'user_id' => $citizen->id,
            'action_name' => 'create_application',
            'arguments' => [
                'license_type_code' => 'private',
                'service_type_code' => 'new_license',
            ],
            'status' => AgentActionStatus::AwaitingConfirmation,
            'requires_confirmation' => true,
            'confirmation_message' => 'test',
        ]);

        $this->postJson("/api/ai-agent/actions/{$action->id}/confirm")
            ->assertStatus(403)
            ->assertJsonPath('message', __('messages.profile.pending_review'));

        $action->refresh();
        $this->assertSame(AgentActionStatus::Failed, $action->status);
        $this->assertSame(__('messages.profile.pending_review'), $action->error_message);
    }

    public function test_duplicate_application_rule_still_works_for_approved_profile(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create(['email_verified_at' => now()]);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        Sanctum::actingAs($citizen);

        $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])->assertOk();

        $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('messages.applications.duplicate_active_application'));
    }
}

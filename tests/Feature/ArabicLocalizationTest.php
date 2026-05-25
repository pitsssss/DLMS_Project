<?php

namespace Tests\Feature;

use App\Models\LicenseType;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class ArabicLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        $this->withoutMiddleware([ThrottleRequests::class]);
        app()->setLocale('ar');
    }

    public function test_ping_returns_arabic_message(): void
    {
        $this->getJson('/api/ping')
            ->assertOk()
            ->assertJsonPath('message', __('messages.ping.running'));
    }

    public function test_login_validation_error_is_arabic(): void
    {
        $this->postJson('/api/auth/login', [])
            ->assertStatus(422)
            ->assertJsonPath('message', __('validation.failed'))
            ->assertJsonPath('errors.password.0', __('validation.required', ['attribute' => __('validation.attributes.password')]));
    }

    public function test_seeded_license_type_names_are_arabic(): void
    {
        $this->seed(LicenseTypesSeeder::class);

        $private = LicenseType::query()->where('code', 'private')->firstOrFail();

        $this->assertSame('رخصة قيادة خاصة', $private->name);
        $this->assertSame('private', $private->code);
    }

    public function test_ai_agent_cancel_reply_is_arabic(): void
    {
        $citizen = \App\Models\User::factory()->create([
            'profile_completed' => true,
            'email_verified_at' => now(),
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($citizen);

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

        $this->postJson("/api/ai-agent/actions/{$action->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.reply', 'تم إلغاء العملية. يمكنك طلب المساعدة من جديد في أي وقت.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_guest_can_submit_contact_message_with_arabic_success(): void
    {
        $response = $this->postJson('/api/contact-messages', [
            'name' => 'زائر',
            'email' => 'guest@example.com',
            'phone' => '0911111111',
            'subject' => 'استفسار عام',
            'message' => 'هذه رسالة تجريبية من زائر.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم إرسال رسالتك بنجاح. سنقوم بالرد عليك في أقرب وقت ممكن.')
            ->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('contact_messages', [
            'subject' => 'استفسار عام',
            'status' => 'new',
            'user_id' => null,
        ]);
    }

    public function test_validation_works(): void
    {
        $this->postJson('/api/contact-messages', [
            'email' => 'not-an-email',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['subject', 'message']);
    }

    public function test_authenticated_citizen_message_stores_user_id_and_prefills_info(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($citizen);

        $response = $this->postJson('/api/contact-messages', [
            'subject' => 'استفسار حول الطلب',
            'message' => 'أريد متابعة حالة طلبي.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'new');

        $this->assertDatabaseHas('contact_messages', [
            'user_id' => $citizen->id,
            'name' => $citizen->name,
            'email' => $citizen->email,
            'subject' => 'استفسار حول الطلب',
        ]);
    }
}

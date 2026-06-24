<?php

namespace Tests\Feature;

use Database\Seeders\FaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class ContentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            FaqSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_faq_endpoint_returns_arabic_faq_list(): void
    {
        $response = $this->getJson('/api/content/faqs');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    ['id', 'category', 'question', 'answer'],
                ],
            ]);

        $this->assertNotEmpty($response->json('data'));
        $this->assertStringNotContainsString('messages.', $response->getContent());
    }

    public function test_privacy_policy_endpoint_returns_title_and_sections(): void
    {
        $response = $this->getJson('/api/content/privacy-policy');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'سياسة الخصوصية')
            ->assertJsonStructure([
                'data' => [
                    'title',
                    'last_updated',
                    'sections' => [
                        ['heading', 'content'],
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.sections'));
        $this->assertStringNotContainsString('messages.', $response->getContent());
    }

    public function test_contact_info_endpoint_returns_phone_email_working_hours(): void
    {
        $response = $this->getJson('/api/content/contact-info');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'title',
                    'description',
                    'phone',
                    'email',
                    'working_hours',
                    'address',
                    'channels' => [
                        ['type', 'label', 'value'],
                    ],
                ],
            ]);

        $this->assertStringNotContainsString('messages.', $response->getContent());
    }
}

<?php

namespace Tests\Feature;

use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTypesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ServiceTypesSeeder::class);
    }

    public function test_service_types_api_returns_description(): void
    {
        $response = $this->getJson('/api/service-types')->assertOk();

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => ['id', 'name', 'code', 'description'],
            ],
        ]);

        $newLicense = collect($response->json('data'))->firstWhere('code', 'new_license');
        $this->assertNotNull($newLicense);
        $this->assertSame('إصدار رخصة جديدة', $newLicense['name']);
        $this->assertSame(
            'قدّم طلب رخصة جديدة وتابع جميع مراحله إلكترونياً.',
            $newLicense['description']
        );
    }

    public function test_seeded_service_type_codes_are_unchanged(): void
    {
        $response = $this->getJson('/api/service-types')->assertOk();

        $codes = collect($response->json('data'))->pluck('code')->sort()->values()->all();

        $this->assertSame([
            'damaged_replacement',
            'license_unblock',
            'lost_replacement',
            'new_license',
            'renew_license',
        ], $codes);
    }

    public function test_seeded_descriptions_are_arabic(): void
    {
        $response = $this->getJson('/api/service-types')->assertOk();

        $expected = [
            'new_license' => 'قدّم طلب رخصة جديدة وتابع جميع مراحله إلكترونياً.',
            'renew_license' => 'جدّد رخصتك بسهولة قبل انتهاء صلاحيتها أو خلال فترة السماح.',
            'lost_replacement' => 'اطلب نسخة جديدة عند فقدان رخصتك.',
            'damaged_replacement' => 'استبدل رخصتك التالفة بنسخة جديدة.',
            'license_unblock' => 'قدّم طلب فك الحظر عن رخصتك بعد استيفاء الشروط المطلوبة.',
        ];

        foreach ($expected as $code => $description) {
            $item = collect($response->json('data'))->firstWhere('code', $code);
            $this->assertNotNull($item, "Missing service type: {$code}");
            $this->assertSame($description, $item['description']);
            $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $item['description']);
        }
    }
}

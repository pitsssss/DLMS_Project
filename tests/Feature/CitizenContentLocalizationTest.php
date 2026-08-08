<?php

namespace Tests\Feature;

use App\Models\Faq;
use App\Models\User;
use App\Support\CitizenContentLocalizer;
use Database\Seeders\FaqSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Lang;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenContentLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
            FaqSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    public function test_faq_is_bilingual(): void
    {
        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/content/faqs')
            ->assertOk();

        $firstAr = $ar->json('data.0');
        $this->assertSame(Lang::get('messages.content.faq.categories.account_profile', [], 'ar'), $firstAr['category']);
        $this->assertSame(Lang::get('messages.content.faq.items.profile_why_complete.question', [], 'ar'), $firstAr['question']);
        $this->assertSame(Lang::get('messages.content.faq.items.profile_why_complete.answer', [], 'ar'), $firstAr['answer']);

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/content/faqs')
            ->assertOk();

        $firstEn = $en->json('data.0');
        $this->assertSame(Lang::get('messages.content.faq.categories.account_profile', [], 'en'), $firstEn['category']);
        $this->assertSame(Lang::get('messages.content.faq.items.profile_why_complete.question', [], 'en'), $firstEn['question']);
        $this->assertSame(Lang::get('messages.content.faq.items.profile_why_complete.answer', [], 'en'), $firstEn['answer']);
        $this->assertSame($firstAr['id'], $firstEn['id']);
        $this->assertStringNotContainsString('لماذا', $firstEn['question']);
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    public function test_privacy_policy_is_bilingual(): void
    {
        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/content/privacy-policy')
            ->assertOk()
            ->assertJsonPath('data.title', Lang::get('messages.content.privacy.title', [], 'ar'))
            ->assertJsonPath('data.last_updated', '2026-06-01');

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/content/privacy-policy')
            ->assertOk()
            ->assertJsonPath('data.title', Lang::get('messages.content.privacy.title', [], 'en'))
            ->assertJsonPath('data.last_updated', '2026-06-01');

        $this->assertSame(count($ar->json('data.sections')), count($en->json('data.sections')));
        $this->assertSame(
            Lang::get('messages.content.privacy.sections.intro.heading', [], 'en'),
            $en->json('data.sections.0.heading')
        );
        $this->assertStringNotContainsString('سياسة', $en->json('data.title'));
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    public function test_contact_presentation_is_bilingual_while_raw_values_stay(): void
    {
        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/content/contact-info')
            ->assertOk();

        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/content/contact-info')
            ->assertOk();

        $this->assertSame(Lang::get('messages.content.contact.title', [], 'en'), $en->json('data.title'));
        $this->assertSame(Lang::get('messages.content.contact.description', [], 'en'), $en->json('data.description'));
        $this->assertSame(Lang::get('messages.content.contact.working_hours', [], 'en'), $en->json('data.working_hours'));
        $this->assertSame(Lang::get('messages.content.contact.channels.phone', [], 'en'), $en->json('data.channels.0.label'));
        $this->assertSame(Lang::get('messages.content.contact.channels.email', [], 'en'), $en->json('data.channels.1.label'));

        $this->assertSame($ar->json('data.phone'), $en->json('data.phone'));
        $this->assertSame($ar->json('data.email'), $en->json('data.email'));
        $this->assertSame($ar->json('data.address'), $en->json('data.address'));
        $this->assertSame($ar->json('data.channels.0.value'), $en->json('data.channels.0.value'));
        $this->assertSame($ar->json('data.channels.1.value'), $en->json('data.channels.1.value'));
        $this->assertSame('011-0000000', $en->json('data.phone'));
        $this->assertSame('support@syrtak.gov.sy', $en->json('data.email'));
        $this->assertSame('دمشق، سوريا', $en->json('data.address'));
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    public function test_theme_labels_are_bilingual_while_codes_remain(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($citizen);

        $ar = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/settings')
            ->assertOk();
        $en = $this->withHeader('Accept-Language', 'en')
            ->getJson('/api/settings')
            ->assertOk();

        $arThemes = collect($ar->json('data.available_themes'))->keyBy('code');
        $enThemes = collect($en->json('data.available_themes'))->keyBy('code');

        $this->assertSame(['light', 'dark', 'system'], $arThemes->keys()->all());
        $this->assertSame($arThemes->keys()->all(), $enThemes->keys()->all());
        $this->assertSame(Lang::get('messages.settings.themes.light', [], 'en'), $enThemes['light']['name']);
        $this->assertSame(Lang::get('messages.settings.themes.dark', [], 'en'), $enThemes['dark']['name']);
        $this->assertSame(Lang::get('messages.settings.themes.system', [], 'en'), $enThemes['system']['name']);
        $this->assertSame(Lang::get('messages.settings.themes.light', [], 'ar'), $arThemes['light']['name']);
        $this->assertStringNotContainsString('الوضع', $enThemes['light']['name']);
        $this->assertStringNotContainsString('messages.', $en->getContent());
    }

    public function test_unknown_faq_falls_back_to_db_values(): void
    {
        $faq = Faq::query()->create([
            'category' => 'فئة غير معروفة',
            'question' => 'سؤال غير معرّف في الترجمة؟',
            'answer' => 'إجابة قاعدة البيانات تبقى كما هي.',
            'sort_order' => 999,
            'is_active' => true,
        ]);

        app()->setLocale('en');
        $localized = CitizenContentLocalizer::faq($faq);

        $this->assertSame('فئة غير معروفة', $localized['category']);
        $this->assertSame('سؤال غير معرّف في الترجمة؟', $localized['question']);
        $this->assertSame('إجابة قاعدة البيانات تبقى كما هي.', $localized['answer']);
    }

    public function test_response_structure_matches_across_locales(): void
    {
        $arFaq = $this->withHeader('Accept-Language', 'ar')->getJson('/api/content/faqs')->assertOk()->json('data');
        $enFaq = $this->withHeader('Accept-Language', 'en')->getJson('/api/content/faqs')->assertOk()->json('data');
        $this->assertSame(count($arFaq), count($enFaq));
        $this->assertSame(array_keys($arFaq[0]), array_keys($enFaq[0]));

        $arPrivacy = $this->withHeader('Accept-Language', 'ar')->getJson('/api/content/privacy-policy')->assertOk()->json('data');
        $enPrivacy = $this->withHeader('Accept-Language', 'en')->getJson('/api/content/privacy-policy')->assertOk()->json('data');
        $this->assertSame(array_keys($arPrivacy), array_keys($enPrivacy));
        $this->assertSame(array_keys($arPrivacy['sections'][0]), array_keys($enPrivacy['sections'][0]));
    }
}

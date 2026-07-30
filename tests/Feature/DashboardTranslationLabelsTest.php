<?php

namespace Tests\Feature;

use App\Enums\DocumentRejectionReason;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\AssertsArabicLabels;
use Tests\Support\FakeDocumentFile;
use Tests\TestCase;

class DashboardTranslationLabelsTest extends TestCase
{
    use AssertsArabicLabels;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed([
            RolesSeeder::class,
            PermissionsSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
            RequiredDocumentsSeeder::class,
        ]);
        $this->withoutMiddleware([ThrottleRequests::class]);
        Storage::fake('local');
    }

    public function test_document_rejection_options_return_arabic_labels_when_app_locale_is_english(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'phone' => '09'.random_int(100000000, 999999999),
            'national_id' => '1'.random_int(100000000, 999999999),
        ]);

        Sanctum::actingAs($citizen);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $applicationId = (int) $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])->assertOk()->json('data.id');

        $this->uploadAllRequired($citizen, $applicationId);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();

        Sanctum::actingAs(User::factory()->dashboardEmployee('profile_document_reviewer')->create());
        $details = $this->getJson("/api/dashboard/document-reviews/{$applicationId}")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($details['rejection_reasons']);
        $this->assertNoRawTranslationKeys($details['rejection_reasons']);

        $unclear = collect($details['rejection_reasons'])->firstWhere('value', 'unclear_document');
        $this->assertSame('unclear_document', $unclear['value']);
        $this->assertSame('الوثيقة غير واضحة', $unclear['label']);

        foreach (DocumentRejectionReason::values() as $code) {
            $option = collect($details['rejection_reasons'])->firstWhere('value', $code);
            $this->assertNotNull($option, "Missing rejection option for {$code}");
            $this->assertStringNotContainsString('messages.', $option['label']);
        }
    }

    public function test_document_rejection_submission_and_notification_use_arabic_labels(): void
    {
        $citizen = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'phone' => '09'.random_int(100000000, 999999999),
            'national_id' => '1'.random_int(100000000, 999999999),
        ]);

        Sanctum::actingAs($citizen);
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $applicationId = (int) $this->postJson('/api/applications', [
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
        ])->assertOk()->json('data.id');

        $this->uploadAllRequired($citizen, $applicationId);
        $this->postJson("/api/applications/{$applicationId}/submit-documents")->assertOk();

        $reviewer = User::factory()->dashboardEmployee('profile_document_reviewer')->create();
        Sanctum::actingAs($reviewer);

        $documentId = (int) collect($this->getJson("/api/dashboard/document-reviews/{$applicationId}")
            ->json('data.documents'))
            ->first(fn (array $row) => ($row['actions']['can_reject'] ?? false) === true)['actions']['document_id'];

        $this->postJson("/api/dashboard/document-reviews/documents/{$documentId}/reject", [
            'rejection_reason_code' => DocumentRejectionReason::UnclearDocument->value,
        ])->assertOk()
            ->assertJsonPath('data.rejection.code', DocumentRejectionReason::UnclearDocument->value)
            ->assertJsonPath('data.rejection.label', 'الوثيقة غير واضحة');

        $audit = AuditLog::query()
            ->where('action', 'document.rejected')
            ->where('entity_id', $documentId)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(DocumentRejectionReason::UnclearDocument->value, $audit->new_values['rejection_reason_code']);
        $this->assertSame('الوثيقة غير واضحة', $audit->new_values['rejection_reason_label']);

        $notification = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', 'document.rejected')
            ->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('الوثيقة غير واضحة', $notification->body);
        $this->assertStringNotContainsString('messages.', $notification->body);
        $this->assertStringNotContainsString('messages.', $notification->title);
    }

    public function test_dashboard_payment_options_return_arabic_labels(): void
    {
        Sanctum::actingAs(User::factory()->dashboardEmployee('payment_employee')->create());
        $data = $this->getJson('/api/dashboard/payments/options')->assertOk()->json('data');

        $this->assertNoRawTranslationKeys($data);
        $pending = collect($data['statuses'])->firstWhere('value', PaymentStatus::Pending->value);
        $this->assertSame('قيد الانتظار', $pending['label']);
    }

    public function test_dashboard_report_options_return_arabic_labels(): void
    {
        Sanctum::actingAs(User::factory()->dashboardAdmin()->create());
        $options = $this->getJson('/api/dashboard/reports/options')->assertOk()->json('data');

        $this->assertNoRawTranslationKeys($options);
        $this->assertNotEmpty($options['application_statuses']);
        $this->assertStringNotContainsString('messages.', $options['application_statuses'][0]['label']);
    }

    public function test_arabic_message_translator_detects_missing_keys(): void
    {
        $this->assertNull(\App\Support\ArabicMessageTranslator::resolve('dashboard.nonexistent.translation.key.for.test'));
        $this->assertSame('test', \App\Support\ArabicMessageTranslator::get('dashboard.nonexistent.translation.key.for.test'));
    }

    private function uploadAllRequired(User $citizen, int $applicationId): void
    {
        Sanctum::actingAs($citizen);
        $checklist = $this->getJson("/api/applications/{$applicationId}/required-documents")
            ->assertOk()
            ->json('data');

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
    }
}

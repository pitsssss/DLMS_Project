<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentRejectionReason;
use App\Enums\DocumentStatus;
use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\RequiredDocument;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Admin\Services\DocumentReviewService;
use App\Modules\Applications\Repositories\ApplicationRepository;
use App\Modules\Fines\Services\FineService;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Notifications\Support\NotificationEventKey;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationIdempotencyTest extends TestCase
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
    }

    public function test_same_event_key_does_not_duplicate(): void
    {
        $citizen = $this->citizen();
        $service = app(NotificationService::class);
        $key = NotificationEventKey::forFine(NotificationType::FineCreated, 55);

        $service->notify($citizen->id, NotificationType::FineCreated, ['fine_id' => 55], ['amount' => 1, 'reason' => 'a'], $key);
        $service->notify($citizen->id, NotificationType::FineCreated, ['fine_id' => 55], ['amount' => 1, 'reason' => 'a'], $key);

        $this->assertSame(1, Notification::query()->where('event_key', $key)->count());
    }

    public function test_same_type_later_event_can_create_another_notification(): void
    {
        $citizen = $this->citizen();
        $admin = User::factory()->dashboardAdmin('admin')->create();
        $fines = app(FineService::class);

        $first = $fines->create($admin, $citizen->id, 1000, 'One');
        $second = $fines->create($admin, $citizen->id, 2000, 'Two');

        $this->assertSame(
            2,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::FineCreated->value)
                ->count()
        );
        $this->assertNotSame(
            Notification::query()->where('data->fine_id', $first->id)->value('event_key'),
            Notification::query()->where('data->fine_id', $second->id)->value('event_key')
        );
    }

    public function test_status_history_event_key_dedupes_reprocessing_same_history_row(): void
    {
        $citizen = $this->citizen();
        $application = $this->applicationFor($citizen, ApplicationStatus::Draft);
        $repo = app(ApplicationRepository::class);

        $repo->transitionStatus($application, ApplicationStatus::PaymentPending, null, 'once');

        $notification = Notification::query()
            ->where('type', NotificationType::ApplicationPaymentPending->value)
            ->firstOrFail();

        app(NotificationService::class)->notifyApplicationStatusChange(
            $application->fresh(),
            ApplicationStatus::PaymentPending,
            (int) str_replace('application.payment_pending:history:', '', (string) $notification->event_key)
        );

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', NotificationType::ApplicationPaymentPending->value)
                ->count()
        );
    }

    public function test_document_rejection_emits_distinct_document_and_application_notifications(): void
    {
        $citizen = $this->citizen();
        $employee = User::factory()->dashboardAdmin('employee')->create();
        $application = $this->applicationFor($citizen, ApplicationStatus::DocumentsUnderReview);
        $document = $this->pendingDocument($application);

        app(DocumentReviewService::class)->reject(
            $employee,
            $document->id,
            DocumentRejectionReason::UnclearDocument,
            null
        );

        $types = Notification::query()
            ->where('user_id', $citizen->id)
            ->pluck('type')
            ->all();

        $this->assertContains(NotificationType::DocumentRejected->value, $types);
        $this->assertContains(NotificationType::ApplicationDocumentsRejected->value, $types);
        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::DocumentRejected->value)->count()
        );
        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::ApplicationDocumentsRejected->value)->count()
        );

        $docNote = Notification::query()->where('type', NotificationType::DocumentRejected->value)->firstOrFail();
        $appNote = Notification::query()->where('type', NotificationType::ApplicationDocumentsRejected->value)->firstOrFail();
        $this->assertNotSame($docNote->body, $appNote->body);
        $this->assertArrayHasKey('document_id', $docNote->data);
        $this->assertArrayNotHasKey('document_id', $appNote->data ?? []);
    }

    private function citizen(): User
    {
        return User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => 'ar',
        ]);
    }

    private function applicationFor(User $citizen, ApplicationStatus $status): LicenseApplication
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        return LicenseApplication::query()->create([
            'application_number' => 'APP-IDM-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => $status,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => null,
            'issued_at' => null,
        ]);
    }

    private function pendingDocument(LicenseApplication $application): ApplicationDocument
    {
        $required = RequiredDocument::query()->create([
            'code' => 'n1_doc_'.Str::lower(Str::random(6)),
            'name' => 'National ID',
            'is_required' => true,
            'is_active' => true,
            'license_type_id' => null,
            'service_type_id' => null,
        ]);

        return ApplicationDocument::query()->create([
            'application_id' => $application->id,
            'required_document_id' => $required->id,
            'file_path' => 'application_documents/'.$application->id.'/demo.pdf',
            'original_name' => 'demo.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'status' => DocumentStatus::PendingReview,
        ]);
    }
}

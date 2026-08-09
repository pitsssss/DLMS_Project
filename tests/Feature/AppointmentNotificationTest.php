<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\NotificationType;
use App\Enums\UserType;
use App\Models\AppointmentSlot;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\ServiceType;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Appointments\Services\AppointmentService;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentNotificationTest extends TestCase
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
            TestTypesSeeder::class,
            FeesSeeder::class,
            AppointmentSlotsSeeder::class,
        ]);
    }

    public function test_book_creates_exactly_one_appointment_booked_notification(): void
    {
        [$citizen, $application, $slot] = $this->readyToBook(['language' => 'en']);

        $appointment = app(AppointmentService::class)->book($citizen, $application->id, $slot->id);

        $notes = Notification::query()
            ->where('user_id', $citizen->id)
            ->where('type', NotificationType::AppointmentBooked->value)
            ->get();

        $this->assertCount(1, $notes);
        $this->assertSame($appointment->id, $notes->first()->data['appointment_id']);
        $this->assertSame($application->id, $notes->first()->data['application_id']);
        $this->assertSame(Lang::get('messages.notifications.appointment_booked_title', [], 'en'), $notes->first()->title);
        $this->assertStringNotContainsString('messages.', $notes->first()->title.$notes->first()->body);

        // InTesting status must not add a second application status notification
        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $citizen->id)
                ->where('type', 'like', 'application.%')
                ->where('type', '!=', NotificationType::AppointmentBooked->value)
                ->count()
        );
    }

    public function test_failed_book_creates_no_notification(): void
    {
        [$citizen, $application] = $this->readyToBook();
        $before = Notification::query()->count();

        try {
            app(AppointmentService::class)->book($citizen, $application->id, 999999);
        } catch (\Throwable) {
        }

        $this->assertSame($before, Notification::query()->count());
    }

    public function test_reschedule_creates_one_rescheduled_without_cancel_or_book_spam(): void
    {
        [$citizen, $application, $slot] = $this->readyToBook();
        $service = app(AppointmentService::class);
        $appointment = $service->book($citizen, $application->id, $slot->id);

        $otherSlot = AppointmentSlot::query()
            ->where('test_type_id', $slot->test_type_id)
            ->where('is_active', true)
            ->whereKeyNot($slot->id)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->firstOrFail();

        $service->reschedule($citizen, $appointment->id, $otherSlot->id);
        $service->reschedule($citizen, $appointment->id, $otherSlot->id); // same slot no-op

        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::AppointmentBooked->value)->count()
        );
        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::AppointmentRescheduled->value)->count()
        );
        $this->assertSame(
            0,
            Notification::query()->where('type', NotificationType::AppointmentCancelled->value)->count()
        );

        // Later legitimate reschedule to a third slot notifies again
        $third = AppointmentSlot::query()
            ->where('test_type_id', $slot->test_type_id)
            ->where('is_active', true)
            ->whereNotIn('id', [$slot->id, $otherSlot->id])
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->first();

        if ($third !== null) {
            $service->reschedule($citizen, $appointment->id, $third->id);
            $this->assertSame(
                2,
                Notification::query()->where('type', NotificationType::AppointmentRescheduled->value)->count()
            );
        }
    }

    public function test_cancel_notifies_once_and_repeat_does_not_duplicate(): void
    {
        [$citizen, $application, $slot] = $this->readyToBook(['language' => 'ar']);
        $service = app(AppointmentService::class);
        $appointment = $service->book($citizen, $application->id, $slot->id);

        $service->cancel($citizen, $appointment->id, 'Changed plans');

        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::AppointmentCancelled->value)->count()
        );
        $cancel = Notification::query()->where('type', NotificationType::AppointmentCancelled->value)->firstOrFail();
        $this->assertSame(Lang::get('messages.notifications.appointment_cancelled_title', [], 'ar'), $cancel->title);

        try {
            $service->cancel($citizen, $appointment->id, 'again');
        } catch (\Throwable) {
        }

        $this->assertSame(
            1,
            Notification::query()->where('type', NotificationType::AppointmentCancelled->value)->count()
        );
    }

    public function test_foreign_citizen_cannot_cancel_and_creates_no_notification_for_owner(): void
    {
        [$owner, $application, $slot] = $this->readyToBook();
        $intruder = User::factory()->withApprovedProfile()->create([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
        ]);
        $service = app(AppointmentService::class);
        $appointment = $service->book($owner, $application->id, $slot->id);
        $beforeOwner = Notification::query()->where('user_id', $owner->id)->count();

        try {
            $service->cancel($intruder, $appointment->id, 'hack');
        } catch (\Throwable) {
        }

        $this->assertSame($beforeOwner, Notification::query()->where('user_id', $owner->id)->count());
        $this->assertSame(0, Notification::query()->where('user_id', $intruder->id)->count());
    }

    /**
     * @return array{0: User, 1: LicenseApplication, 2: AppointmentSlot}
     */
    private function readyToBook(array $citizenOverrides = []): array
    {
        $citizen = User::factory()->withApprovedProfile()->create(array_merge([
            'email_verified_at' => now(),
            'user_type' => UserType::Citizen,
            'language' => 'ar',
        ], $citizenOverrides));

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();

        $application = LicenseApplication::query()->create([
            'application_number' => 'APP-AN-'.strtoupper(Str::random(6)),
            'citizen_id' => $citizen->id,
            'license_type_id' => $licenseType->id,
            'service_type_id' => $serviceType->id,
            'status' => ApplicationStatus::AppointmentPending,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => now(),
            'approved_at' => null,
            'issued_at' => null,
        ]);

        $vision = TestType::query()->where('code', 'vision')->firstOrFail();
        $slot = AppointmentSlot::query()
            ->where('test_type_id', $vision->id)
            ->where('is_active', true)
            ->whereColumn('booked_count', '<', 'capacity')
            ->where('date', '>=', now()->toDateString())
            ->firstOrFail();

        return [$citizen, $application, $slot];
    }
}

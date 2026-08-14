<?php

namespace Database\Seeders\Support;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProfileStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\AppointmentSlot;
use App\Models\Fee;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseStatusHistory;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\RequiredDocument;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Payments\Support\ApplicationFeeResolver;
use Carbon\Carbon;
use Database\Seeders\AppointmentCentersSeeder;
use Database\Seeders\AppointmentSlotsSeeder;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RequiredDocumentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class CommitteeDemoKit
{
    public const PASSWORD = 'CommitteeDemo!2026';

    public const EXAMINER_EMAIL = 'committee.examiner@syrtak.local';

    public const ISSUER_EMAIL = 'committee.issuer@syrtak.local';

    public const APP_A = 'DEMO-COMMITTEE-A';

    public const APP_B = 'DEMO-COMMITTEE-B';

    public const APP_C = 'DEMO-COMMITTEE-C';

    public const APP_D = 'DEMO-COMMITTEE-D';

    public function __construct(
        private readonly Seeder $seeder,
        private readonly ?Command $command = null,
    ) {}

    public function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Committee demo seeders may only run in the local or testing environment.'
            );
        }
    }

    public function ensureCatalog(): void
    {
        $this->seeder->call([
            RolesSeeder::class,
            PermissionsSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
            TestTypesSeeder::class,
            RequiredDocumentsSeeder::class,
            FeesSeeder::class,
            AppointmentCentersSeeder::class,
            AppointmentSlotsSeeder::class,
        ]);
    }

    /**
     * @return array{examiner: User, issuer: User}
     */
    public function ensureEmployees(): array
    {
        return [
            'examiner' => $this->upsertEmployee(
                email: self::EXAMINER_EMAIL,
                name: 'ممتحن لجنة سرتاك (تجريبي)',
                phone: '0911000001',
                roleName: 'test_employee',
            ),
            'issuer' => $this->upsertEmployee(
                email: self::ISSUER_EMAIL,
                name: 'مصدر رخص لجنة سرتاك (تجريبي)',
                phone: '0911000002',
                roleName: 'license_employee',
            ),
        ];
    }

    public function seedScenarioA(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-a@syrtak.local',
            'name' => 'مواطن تجريبي - إصدار بعد الاختبار',
            'phone' => '0912000001',
            'national_id' => '09020000001',
        ]);

        $application = $this->upsertNewLicenseApplication($citizen, self::APP_A, ApplicationStatus::InTesting);
        $this->attachApprovedDocuments($application, $examiner);
        $this->attachCompletedFee($application, $citizen);
        $this->clearUnpaidFines($citizen);

        $this->completePassedTest($application, $citizen, $examiner, 'vision', now()->subDays(2));
        $this->completePassedTest($application, $citizen, $examiner, 'theory', now()->subDay());
        $this->bookWaitingAppointment($application, $citizen, 'practical');

        $practical = $this->testType('practical');
        $application->current_test_type_id = $practical->id;
        $application->status = ApplicationStatus::InTesting;
        $application->approved_at = null;
        $application->issued_at = null;
        $application->save();

        $this->replaceStatusHistory($application, ApplicationStatus::InTesting, $examiner, 'Committee demo: waiting for practical result.');

        return $application->fresh(['citizen', 'serviceType', 'licenseType', 'currentTestType']);
    }

    public function seedScenarioB(User $examiner): LicenseApplication
    {
        return $this->seedWaitingFirstTest(
            $examiner,
            self::APP_B,
            [
                'email' => 'committee.scenario-b@syrtak.local',
                'name' => 'مواطن تجريبي - رسوب',
                'phone' => '0912000002',
                'national_id' => '09020000002',
            ],
            'Committee demo: waiting for vision result (failed path).'
        );
    }

    public function seedScenarioC(User $examiner): LicenseApplication
    {
        return $this->seedWaitingFirstTest(
            $examiner,
            self::APP_C,
            [
                'email' => 'committee.scenario-c@syrtak.local',
                'name' => 'مواطن تجريبي - عدم حضور',
                'phone' => '0912000003',
                'national_id' => '09020000003',
            ],
            'Committee demo: waiting for vision result (no-show path).'
        );
    }

    public function seedScenarioD(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-d@syrtak.local',
            'name' => 'مواطن تجريبي - جاهز للإصدار',
            'phone' => '0912000004',
            'national_id' => '09020000004',
        ]);

        $application = $this->upsertNewLicenseApplication($citizen, self::APP_D, ApplicationStatus::Approved);
        $this->attachApprovedDocuments($application, $examiner);
        $this->attachCompletedFee($application, $citizen);
        $this->clearUnpaidFines($citizen);

        $this->completePassedTest($application, $citizen, $examiner, 'vision', now()->subDays(3));
        $this->completePassedTest($application, $citizen, $examiner, 'theory', now()->subDays(2));
        $this->completePassedTest($application, $citizen, $examiner, 'practical', now()->subDay());

        $application->current_test_type_id = null;
        $application->status = ApplicationStatus::Approved;
        $application->approved_at = now()->subDay();
        $application->issued_at = null;
        $application->save();

        $this->replaceStatusHistory($application, ApplicationStatus::Approved, $examiner, 'Committee demo: ready for license issuance.');

        return $application->fresh(['citizen', 'serviceType', 'licenseType']);
    }

    public function waitingAppointmentId(LicenseApplication $application): ?int
    {
        $appointment = TestAppointment::query()
            ->where('application_id', $application->id)
            ->where('status', AppointmentStatus::Booked)
            ->whereDoesntHave('testResult')
            ->orderByDesc('id')
            ->first();

        return $appointment?->id;
    }

    public function info(string $message): void
    {
        $this->command?->info($message);
    }

    public function reportScenario(string $label, LicenseApplication $application, ?int $appointmentId, string $page): void
    {
        $application->loadMissing('citizen');
        $this->info("{$label}");
        $this->info('  citizen: '.$application->citizen?->name.' <'.$application->citizen?->email.'>');
        $this->info('  application_id: '.$application->id);
        $this->info('  application_number: '.$application->application_number);
        if ($appointmentId !== null) {
            $this->info('  appointment_id: '.$appointmentId);
        }
        $status = $application->status instanceof ApplicationStatus
            ? $application->status->value
            : (string) $application->status;
        $this->info('  status: '.$status);
        $this->info('  dashboard: '.$page);
    }

    /**
     * @param  array{email: string, name: string, phone: string, national_id: string}  $citizen
     */
    private function seedWaitingFirstTest(User $examiner, string $applicationNumber, array $citizen, string $historyNote): LicenseApplication
    {
        $user = $this->upsertCitizen($citizen);
        $application = $this->upsertNewLicenseApplication($user, $applicationNumber, ApplicationStatus::InTesting);
        $this->attachApprovedDocuments($application, $examiner);
        $this->attachCompletedFee($application, $user);
        $this->clearUnpaidFines($user);
        $this->bookWaitingAppointment($application, $user, 'vision');

        $vision = $this->testType('vision');
        $application->current_test_type_id = $vision->id;
        $application->status = ApplicationStatus::InTesting;
        $application->approved_at = null;
        $application->issued_at = null;
        $application->save();

        $this->replaceStatusHistory($application, ApplicationStatus::InTesting, $examiner, $historyNote);

        return $application->fresh(['citizen', 'serviceType', 'licenseType', 'currentTestType']);
    }

    private function upsertEmployee(string $email, string $name, string $phone, string $roleName): User
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => $phone,
                'national_id' => null,
                'password' => self::PASSWORD,
                'role_id' => $role->id,
                'user_type' => UserType::Employee,
                'language' => 'ar',
                'profile_completed' => true,
                'profile_status' => ProfileStatus::Approved,
                'is_active' => true,
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ]
        );
    }

    /**
     * @param  array{email: string, name: string, phone: string, national_id: string}  $record
     */
    private function upsertCitizen(array $record): User
    {
        $role = Role::query()->where('name', 'citizen')->firstOrFail();

        return User::query()->updateOrCreate(
            ['email' => $record['email']],
            [
                'name' => $record['name'],
                'phone' => $record['phone'],
                'national_id' => $record['national_id'],
                'password' => self::PASSWORD,
                'role_id' => $role->id,
                'user_type' => UserType::Citizen,
                'birth_date' => '1992-05-15',
                'governorate' => 'دمشق',
                'address' => 'دمشق — المزة — بيانات تجريبية للجنة',
                'language' => 'ar',
                'profile_completed' => true,
                'profile_status' => ProfileStatus::Approved,
                'profile_submitted_at' => now()->subDays(20),
                'profile_reviewed_at' => now()->subDays(19),
                'profile_rejection_reason' => null,
                'is_active' => true,
                'email_verified_at' => now()->subDays(20),
                'phone_verified_at' => now()->subDays(20),
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ]
        );
    }

    private function upsertNewLicenseApplication(User $citizen, string $applicationNumber, ApplicationStatus $status): LicenseApplication
    {
        $this->resetCommitteeApplication($applicationNumber);

        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $submittedAt = now()->subDays(10);

        return LicenseApplication::query()->updateOrCreate(
            ['application_number' => $applicationNumber],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'related_license_id' => null,
                'status' => $status,
                'current_test_type_id' => null,
                'rejection_reason' => null,
                'submitted_at' => $submittedAt,
                'approved_at' => $status === ApplicationStatus::Approved ? now()->subDay() : null,
                'issued_at' => null,
                'deleted_at' => null,
            ]
        );
    }

    private function resetCommitteeApplication(string $applicationNumber): void
    {
        if (! str_starts_with($applicationNumber, 'DEMO-COMMITTEE-')) {
            throw new RuntimeException('Refusing to reset a non-committee application.');
        }

        $application = LicenseApplication::withTrashed()
            ->where('application_number', $applicationNumber)
            ->first();

        if ($application === null) {
            return;
        }

        $licenses = License::withTrashed()->where('application_id', $application->id)->get();
        foreach ($licenses as $license) {
            LicenseStatusHistory::query()->where('license_id', $license->id)->delete();
            $license->forceDelete();
        }

        TestResult::query()->where('application_id', $application->id)->delete();

        $appointments = TestAppointment::withTrashed()->where('application_id', $application->id)->get();
        $slotIds = $appointments->pluck('appointment_slot_id')->filter()->unique();
        foreach ($appointments as $appointment) {
            $appointment->forceDelete();
        }
        $this->syncSlotBookedCounts($slotIds->all());

        ApplicationStatusHistory::query()->where('application_id', $application->id)->delete();

        if ($application->trashed()) {
            $application->restore();
        }
    }

    private function attachApprovedDocuments(LicenseApplication $application, User $reviewer): void
    {
        $required = RequiredDocument::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->where(function ($q) use ($application): void {
                $q->whereNull('license_type_id')->orWhere('license_type_id', $application->license_type_id);
            })
            ->where(function ($q) use ($application): void {
                $q->whereNull('service_type_id')->orWhere('service_type_id', $application->service_type_id);
            })
            ->get();

        foreach ($required as $document) {
            $path = $this->putDemoFile($application, $document);

            ApplicationDocument::withTrashed()->updateOrCreate(
                [
                    'application_id' => $application->id,
                    'required_document_id' => $document->id,
                ],
                [
                    'file_path' => $path,
                    'original_name' => $document->code.'.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => Storage::disk('local')->size($path),
                    'status' => DocumentStatus::Approved,
                    'rejection_reason' => null,
                    'rejection_reason_code' => null,
                    'rejection_details' => null,
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now()->subDays(8),
                    'deleted_at' => null,
                ]
            );
        }
    }

    private function attachCompletedFee(LicenseApplication $application, User $citizen): void
    {
        $fee = app(ApplicationFeeResolver::class)->resolve($application);
        $paymentNumber = $application->application_number.'-PAY';

        Payment::withTrashed()->updateOrCreate(
            ['payment_number' => $paymentNumber],
            [
                'user_id' => $citizen->id,
                'application_id' => $application->id,
                'fine_id' => null,
                'fee_id' => $fee->id,
                'payable_type' => Fee::class,
                'payable_id' => $fee->id,
                'amount' => $fee->amount,
                'currency' => $fee->currency,
                'status' => PaymentStatus::Completed,
                'provider' => 'mock',
                'provider_reference' => $application->application_number.'-FEE',
                'paid_at' => now()->subDays(7),
                'metadata' => ['source' => 'committee_demo'],
                'failure_code' => null,
                'failure_message' => null,
                'failed_at' => null,
                'settled_obligation_key' => Payment::obligationKey($application->id, $fee->id),
                'active_obligation_key' => null,
                'deleted_at' => null,
            ]
        );
    }

    private function clearUnpaidFines(User $citizen): void
    {
        Fine::query()
            ->where('citizen_id', $citizen->id)
            ->where('status', FineStatus::Unpaid)
            ->get()
            ->each(fn (Fine $fine) => $fine->delete());
    }

    private function completePassedTest(
        LicenseApplication $application,
        User $citizen,
        User $examiner,
        string $testTypeCode,
        Carbon $when
    ): void {
        $testType = $this->testType($testTypeCode);
        $slot = $this->slotFor($testType, preferPast: true);
        $scheduledAt = $this->scheduledAt($slot, $when);

        $appointment = TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Completed,
            'scheduled_at' => $scheduledAt,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);

        TestResult::query()->create([
            'application_id' => $application->id,
            'test_appointment_id' => $appointment->id,
            'test_type_id' => $testType->id,
            'result' => TestResultStatus::Passed,
            'attempt_number' => 1,
            'notes' => 'نتيجة تجريبية للجنة — اجتياز '.$testTypeCode,
            'recorded_by' => $examiner->id,
            'recorded_at' => $scheduledAt->copy()->addHours(2),
        ]);
    }

    private function bookWaitingAppointment(LicenseApplication $application, User $citizen, string $testTypeCode): TestAppointment
    {
        $testType = $this->testType($testTypeCode);
        $slot = $this->slotFor($testType, preferPast: false);

        $appointment = TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => $this->scheduledAt($slot),
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);

        $slot->increment('booked_count');

        return $appointment;
    }

    private function testType(string $code): TestType
    {
        return TestType::query()->where('code', $code)->where('is_active', true)->firstOrFail();
    }

    private function slotFor(TestType $testType, bool $preferPast): AppointmentSlot
    {
        $base = AppointmentSlot::query()
            ->where('test_type_id', $testType->id)
            ->where('is_active', true);

        $today = now()->toDateString();

        $slot = $preferPast
            ? (clone $base)->whereDate('date', '<=', $today)->orderByDesc('date')->orderBy('start_time')->first()
            : (clone $base)
                ->whereDate('date', '>=', $today)
                ->whereColumn('booked_count', '<', 'capacity')
                ->orderBy('date')
                ->orderBy('start_time')
                ->first();

        $slot ??= (clone $base)->orderByDesc('date')->orderBy('start_time')->first();

        if ($slot === null) {
            throw new RuntimeException('No appointment slot found for test type '.$testType->code.'. Run AppointmentSlotsSeeder first.');
        }

        return $slot;
    }

    private function scheduledAt(AppointmentSlot $slot, ?Carbon $fallbackDay = null): Carbon
    {
        $date = $slot->date?->format('Y-m-d') ?? ($fallbackDay ?? now())->toDateString();

        return Carbon::parse($date.' '.(string) $slot->start_time);
    }

    /**
     * @param  list<int|string|null>  $slotIds
     */
    private function syncSlotBookedCounts(array $slotIds): void
    {
        foreach (array_unique(array_filter($slotIds)) as $slotId) {
            $slot = AppointmentSlot::query()->find($slotId);
            if ($slot === null) {
                continue;
            }
            $slot->booked_count = $slot->activeBookedCount();
            $slot->save();
        }
    }

    private function replaceStatusHistory(LicenseApplication $application, ApplicationStatus $status, User $actor, string $notes): void
    {
        ApplicationStatusHistory::query()->where('application_id', $application->id)->delete();
        ApplicationStatusHistory::query()->create([
            'application_id' => $application->id,
            'old_status' => ApplicationStatus::PaymentPending,
            'new_status' => $status,
            'changed_by' => $actor->id,
            'reason' => null,
            'notes' => $notes,
        ]);
    }

    private function putDemoFile(LicenseApplication $application, RequiredDocument $document): string
    {
        $path = 'application_documents/'.$application->id.'/demo-committee-'.$document->code.'.pdf';
        Storage::disk('local')->put($path, $this->demoPdf($application->application_number, $document->code));

        return $path;
    }

    private function demoPdf(string $applicationNumber, string $code): string
    {
        $title = $code.' - '.$applicationNumber;

        return "%PDF-1.4\n"
            ."1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            ."2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
            ."3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n"
            ."4 0 obj << /Length ".(80 + strlen($title))." >> stream\n"
            ."BT /F1 16 Tf 72 720 Td (Committee demo document) Tj 0 -24 Td ({$title}) Tj ET\n"
            ."endstream endobj\n"
            ."5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            ."xref\n0 6\n0000000000 65535 f \n"
            ."trailer << /Root 1 0 R /Size 6 >>\nstartxref\n0\n%%EOF\n";
    }
}

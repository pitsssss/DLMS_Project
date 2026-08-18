<?php

namespace Database\Seeders\Support;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
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

    /** Final practical waiting — record passed → approved. */
    public const APP_A = 'DEMO-COMMITTEE-A';

    /** Vision waiting — record failed path. */
    public const APP_B = 'DEMO-COMMITTEE-B';

    /** Vision waiting — record no_show path. */
    public const APP_C = 'DEMO-COMMITTEE-C';

    /** New private — ready to issue. */
    public const APP_D = 'DEMO-COMMITTEE-D';

    /** Theory waiting (vision already passed). */
    public const APP_E = 'DEMO-COMMITTEE-E';

    /** Waiting retest after 1 fail — previous_attempts_count = 1. */
    public const APP_F = 'DEMO-COMMITTEE-F';

    /** Waiting last attempt (2 prior fails) — next fail → administrative_review. */
    public const APP_G = 'DEMO-COMMITTEE-G';

    /** Completed + passed — filter status=completed. */
    public const APP_H = 'DEMO-COMMITTEE-H';

    /** Completed + failed result — filter status=completed. */
    public const APP_I = 'DEMO-COMMITTEE-I';

    /** Cancelled appointment — filter status=cancelled. */
    public const APP_J = 'DEMO-COMMITTEE-J';

    /** Appointment status=no_show — filter status=no_show. */
    public const APP_K = 'DEMO-COMMITTEE-K';

    /** Booked but app not recordable (approved) — can_record_result=false. */
    public const APP_L = 'DEMO-COMMITTEE-L';

    /** New public — ready to issue. */
    public const APP_M = 'DEMO-COMMITTEE-M';

    /** Renew — ready (related license). */
    public const APP_N = 'DEMO-COMMITTEE-N';

    public const APP_N_ORIG = 'DEMO-COMMITTEE-N-ORIG';

    /** Lost replacement — ready. */
    public const APP_O = 'DEMO-COMMITTEE-O';

    public const APP_O_ORIG = 'DEMO-COMMITTEE-O-ORIG';

    /** Damaged replacement — ready. */
    public const APP_P = 'DEMO-COMMITTEE-P';

    public const APP_P_ORIG = 'DEMO-COMMITTEE-P-ORIG';

    /** Approved but unpaid fee — details blockers only. */
    public const APP_Q = 'DEMO-COMMITTEE-Q';

    /** Approved + unpaid fine — details blockers only. */
    public const APP_R = 'DEMO-COMMITTEE-R';

    /** Already issued — excluded from queue. */
    public const APP_S = 'DEMO-COMMITTEE-S';

    /** Approved missing docs — details blockers only. */
    public const APP_T = 'DEMO-COMMITTEE-T';

    /** New truck — ready to issue (license type variety). */
    public const APP_U = 'DEMO-COMMITTEE-U';

    public function __construct(
        private readonly Seeder $seeder,
        private readonly ?Command $command = null,
    ) {}

    public function guardEnvironment(): void
    {
        if (! DemoSeeding::isAllowed()) {
            throw new RuntimeException(
                DemoSeeding::refusalMessage('Committee demo seeders')
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

    // ── Test-result queue scenarios ──────────────────────────────────────────

    public function seedScenarioA(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-a@syrtak.local',
            'name' => 'مواطن تجريبي - إصدار بعد الاختبار',
            'phone' => '0912000001',
            'national_id' => '09020000001',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_A, ApplicationStatus::InTesting);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);

        $this->completePassedTest($application, $citizen, $examiner, 'vision', now()->subDays(2));
        $this->completePassedTest($application, $citizen, $examiner, 'theory', now()->subDay());
        $this->bookWaitingAppointment($application, $citizen, 'practical');

        return $this->finalizeTestingApp($application, $examiner, 'practical', ApplicationStatus::InTesting, 'Committee demo: waiting for practical result.');
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

    public function seedScenarioE(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-e@syrtak.local',
            'name' => 'مواطن تجريبي - انتظار النظري',
            'phone' => '0912000005',
            'national_id' => '09020000005',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_E, ApplicationStatus::InTesting);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);
        $this->completePassedTest($application, $citizen, $examiner, 'vision', now()->subDays(2));
        $this->bookWaitingAppointment($application, $citizen, 'theory');

        return $this->finalizeTestingApp($application, $examiner, 'theory', ApplicationStatus::InTesting, 'Committee demo: waiting for theory result.');
    }

    public function seedScenarioF(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-f@syrtak.local',
            'name' => 'مواطن تجريبي - إعادة اختبار بعد رسوب',
            'phone' => '0912000006',
            'national_id' => '09020000006',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_F, ApplicationStatus::WaitingRetest);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);
        $this->completeAttemptedTest($application, $citizen, $examiner, 'vision', TestResultStatus::Failed, 1, now()->subDays(5));
        $this->bookWaitingAppointment($application, $citizen, 'vision');

        return $this->finalizeTestingApp($application, $examiner, 'vision', ApplicationStatus::WaitingRetest, 'Committee demo: retest after one fail (attempt 2).');
    }

    public function seedScenarioG(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-g@syrtak.local',
            'name' => 'مواطن تجريبي - المحاولة الأخيرة',
            'phone' => '0912000007',
            'national_id' => '09020000007',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_G, ApplicationStatus::WaitingRetest);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);
        $this->completeAttemptedTest($application, $citizen, $examiner, 'vision', TestResultStatus::Failed, 1, now()->subDays(10));
        $this->completeAttemptedTest($application, $citizen, $examiner, 'vision', TestResultStatus::NoShow, 2, now()->subDays(5));
        $this->bookWaitingAppointment($application, $citizen, 'vision');

        return $this->finalizeTestingApp($application, $examiner, 'vision', ApplicationStatus::WaitingRetest, 'Committee demo: last attempt (attempt 3 / max).');
    }

    public function seedScenarioH(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-h@syrtak.local',
            'name' => 'مواطن تجريبي - موعد مكتمل ناجح',
            'phone' => '0912000008',
            'national_id' => '09020000008',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_H, ApplicationStatus::InTesting);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);
        $this->completePassedTest($application, $citizen, $examiner, 'vision', now()->subDays(3));
        $this->bookWaitingAppointment($application, $citizen, 'theory');

        return $this->finalizeTestingApp($application, $examiner, 'theory', ApplicationStatus::InTesting, 'Committee demo: completed passed history + waiting theory.');
    }

    public function seedScenarioI(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-i@syrtak.local',
            'name' => 'مواطن تجريبي - موعد مكتمل راسب',
            'phone' => '0912000009',
            'national_id' => '09020000009',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_I, ApplicationStatus::WaitingRetest);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);
        $this->completeAttemptedTest($application, $citizen, $examiner, 'theory', TestResultStatus::Failed, 1, now()->subDays(2));
        $this->bookWaitingAppointment($application, $citizen, 'theory');

        return $this->finalizeTestingApp($application, $examiner, 'theory', ApplicationStatus::WaitingRetest, 'Committee demo: completed failed history + waiting retest.');
    }

    public function seedScenarioJ(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-j@syrtak.local',
            'name' => 'مواطن تجريبي - موعد ملغى',
            'phone' => '0912000010',
            'national_id' => '09020000010',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_J, ApplicationStatus::InTesting);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);
        $this->createCancelledAppointment($application, $citizen, 'vision', now()->subDays(1));
        $this->bookWaitingAppointment($application, $citizen, 'vision');

        return $this->finalizeTestingApp($application, $examiner, 'vision', ApplicationStatus::InTesting, 'Committee demo: cancelled appointment history.');
    }

    public function seedScenarioK(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-k@syrtak.local',
            'name' => 'مواطن تجريبي - حالة غياب للموعد',
            'phone' => '0912000011',
            'national_id' => '09020000011',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_K, ApplicationStatus::WaitingRetest);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);
        $this->completeAttemptedTest($application, $citizen, $examiner, 'practical', TestResultStatus::NoShow, 1, now()->subDays(4), AppointmentStatus::NoShow);
        $this->bookWaitingAppointment($application, $citizen, 'practical');

        return $this->finalizeTestingApp($application, $examiner, 'practical', ApplicationStatus::WaitingRetest, 'Committee demo: appointment status=no_show history.');
    }

    public function seedScenarioL(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-l@syrtak.local',
            'name' => 'مواطن تجريبي - محجوز غير قابل للتسجيل',
            'phone' => '0912000012',
            'national_id' => '09020000012',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_L, ApplicationStatus::Approved);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);
        $this->completePassedTest($application, $citizen, $examiner, 'vision', now()->subDays(5));
        $this->completePassedTest($application, $citizen, $examiner, 'theory', now()->subDays(4));
        $this->completePassedTest($application, $citizen, $examiner, 'practical', now()->subDays(3));
        $this->bookWaitingAppointment($application, $citizen, 'vision');

        $application->current_test_type_id = null;
        $application->status = ApplicationStatus::Approved;
        $application->approved_at = now()->subDays(2);
        $application->issued_at = null;
        $application->save();
        $this->replaceStatusHistory($application, ApplicationStatus::Approved, $examiner, 'Committee demo: booked row but app approved → can_record_result=false.');

        return $application->fresh(['citizen', 'serviceType', 'licenseType', 'currentTestType']);
    }

    // ── License issuance scenarios ───────────────────────────────────────────

    public function seedScenarioD(User $examiner): LicenseApplication
    {
        return $this->seedReadyNewLicense(
            $examiner,
            self::APP_D,
            [
                'email' => 'committee.scenario-d@syrtak.local',
                'name' => 'مواطن تجريبي - جاهز للإصدار',
                'phone' => '0912000004',
                'national_id' => '09020000004',
            ],
            'private',
            'Committee demo: ready for license issuance (private).'
        );
    }

    public function seedScenarioM(User $examiner): LicenseApplication
    {
        return $this->seedReadyNewLicense(
            $examiner,
            self::APP_M,
            [
                'email' => 'committee.scenario-m@syrtak.local',
                'name' => 'مواطن تجريبي - إصدار رخصة عامة',
                'phone' => '0912000013',
                'national_id' => '09020000013',
            ],
            'public',
            'Committee demo: ready for license issuance (public).'
        );
    }

    public function seedScenarioU(User $examiner): LicenseApplication
    {
        return $this->seedReadyNewLicense(
            $examiner,
            self::APP_U,
            [
                'email' => 'committee.scenario-u@syrtak.local',
                'name' => 'مواطن تجريبي - إصدار رخصة شاحنة',
                'phone' => '0912000021',
                'national_id' => '09020000021',
            ],
            'truck',
            'Committee demo: ready for license issuance (truck).'
        );
    }

    public function seedScenarioN(User $examiner): LicenseApplication
    {
        return $this->seedReadyFollowOn(
            $examiner,
            self::APP_N,
            self::APP_N_ORIG,
            'renew_license',
            [
                'email' => 'committee.scenario-n@syrtak.local',
                'name' => 'مواطن تجريبي - تجديد رخصة',
                'phone' => '0912000014',
                'national_id' => '09020000014',
            ],
            'LIC-DEMO-COMMITTEE-N',
            'Committee demo: renew ready to issue.'
        );
    }

    public function seedScenarioO(User $examiner): LicenseApplication
    {
        return $this->seedReadyFollowOn(
            $examiner,
            self::APP_O,
            self::APP_O_ORIG,
            'lost_replacement',
            [
                'email' => 'committee.scenario-o@syrtak.local',
                'name' => 'مواطن تجريبي - بدل فاقد',
                'phone' => '0912000015',
                'national_id' => '09020000015',
            ],
            'LIC-DEMO-COMMITTEE-O',
            'Committee demo: lost replacement ready to issue.'
        );
    }

    public function seedScenarioP(User $examiner): LicenseApplication
    {
        return $this->seedReadyFollowOn(
            $examiner,
            self::APP_P,
            self::APP_P_ORIG,
            'damaged_replacement',
            [
                'email' => 'committee.scenario-p@syrtak.local',
                'name' => 'مواطن تجريبي - بدل تالف',
                'phone' => '0912000016',
                'national_id' => '09020000016',
            ],
            'LIC-DEMO-COMMITTEE-P',
            'Committee demo: damaged replacement ready to issue.'
        );
    }

    public function seedScenarioQ(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-q@syrtak.local',
            'name' => 'مواطن تجريبي - معتمد بلا دفع',
            'phone' => '0912000017',
            'national_id' => '09020000017',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_Q, ApplicationStatus::Approved);
        $this->attachApprovedDocuments($application, $examiner);
        $this->clearUnpaidFines($citizen);
        $this->attachPendingFee($application, $citizen);
        $this->completeAllRequiredTests($application, $citizen, $examiner);

        $application->current_test_type_id = null;
        $application->status = ApplicationStatus::Approved;
        $application->approved_at = now()->subHours(6);
        $application->issued_at = null;
        $application->save();
        $this->replaceStatusHistory($application, ApplicationStatus::Approved, $examiner, 'Committee demo: approved but unpaid (payment_required).');

        return $application->fresh(['citizen', 'serviceType', 'licenseType']);
    }

    public function seedScenarioR(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-r@syrtak.local',
            'name' => 'مواطن تجريبي - معتمد مع مخالفة',
            'phone' => '0912000018',
            'national_id' => '09020000018',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_R, ApplicationStatus::Approved);
        $this->attachApprovedDocuments($application, $examiner);
        $this->attachCompletedFee($application, $citizen);
        $this->clearUnpaidFines($citizen);
        $this->completeAllRequiredTests($application, $citizen, $examiner);
        $this->createUnpaidFine($citizen, 'مخالفة تجريبية — تمنع الإصدار');

        $application->current_test_type_id = null;
        $application->status = ApplicationStatus::Approved;
        $application->approved_at = now()->subHours(5);
        $application->issued_at = null;
        $application->save();
        $this->replaceStatusHistory($application, ApplicationStatus::Approved, $examiner, 'Committee demo: approved with unpaid fine.');

        return $application->fresh(['citizen', 'serviceType', 'licenseType']);
    }

    public function seedScenarioS(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-s@syrtak.local',
            'name' => 'مواطن تجريبي - صادر مسبقاً',
            'phone' => '0912000019',
            'national_id' => '09020000019',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_S, ApplicationStatus::LicenseIssued);
        $this->prepareIssuancePrereqs($application, $citizen, $examiner);
        $this->completeAllRequiredTests($application, $citizen, $examiner);

        $application->current_test_type_id = null;
        $application->status = ApplicationStatus::LicenseIssued;
        $application->approved_at = now()->subDays(3);
        $application->issued_at = now()->subDay();
        $application->save();

        License::query()->updateOrCreate(
            ['license_number' => 'LIC-DEMO-COMMITTEE-S'],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $application->license_type_id,
                'application_id' => $application->id,
                'status' => LicenseStatus::Active,
                'issue_date' => now()->subDay()->toDateString(),
                'expiry_date' => now()->addYears(5)->toDateString(),
                'issued_by' => $examiner->id,
                'deleted_at' => null,
            ]
        );

        $this->replaceStatusHistory($application, ApplicationStatus::LicenseIssued, $examiner, 'Committee demo: already issued.');

        return $application->fresh(['citizen', 'serviceType', 'licenseType', 'license']);
    }

    public function seedScenarioT(User $examiner): LicenseApplication
    {
        $citizen = $this->upsertCitizen([
            'email' => 'committee.scenario-t@syrtak.local',
            'name' => 'مواطن تجريبي - معتمد بلا وثائق كاملة',
            'phone' => '0912000020',
            'national_id' => '09020000020',
        ]);

        $application = $this->upsertApplication($citizen, self::APP_T, ApplicationStatus::Approved);
        $this->attachCompletedFee($application, $citizen);
        $this->clearUnpaidFines($citizen);
        $this->completeAllRequiredTests($application, $citizen, $examiner);
        $this->attachPartialDocuments($application, $examiner);

        $application->current_test_type_id = null;
        $application->status = ApplicationStatus::Approved;
        $application->approved_at = now()->subHours(4);
        $application->issued_at = null;
        $application->save();
        $this->replaceStatusHistory($application, ApplicationStatus::Approved, $examiner, 'Committee demo: approved missing documents.');

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
        $application = $this->upsertApplication($user, $applicationNumber, ApplicationStatus::InTesting);
        $this->prepareIssuancePrereqs($application, $user, $examiner);
        $this->bookWaitingAppointment($application, $user, 'vision');

        return $this->finalizeTestingApp($application, $examiner, 'vision', ApplicationStatus::InTesting, $historyNote);
    }

    /**
     * @param  array{email: string, name: string, phone: string, national_id: string}  $citizen
     */
    private function seedReadyNewLicense(
        User $examiner,
        string $applicationNumber,
        array $citizen,
        string $licenseTypeCode,
        string $historyNote,
    ): LicenseApplication {
        $user = $this->upsertCitizen($citizen);
        $application = $this->upsertApplication($user, $applicationNumber, ApplicationStatus::Approved, 'new_license', $licenseTypeCode);
        $this->prepareIssuancePrereqs($application, $user, $examiner);
        $this->completeAllRequiredTests($application, $user, $examiner);

        $application->current_test_type_id = null;
        $application->status = ApplicationStatus::Approved;
        $application->approved_at = now()->subDay();
        $application->issued_at = null;
        $application->save();
        $this->replaceStatusHistory($application, ApplicationStatus::Approved, $examiner, $historyNote);

        return $application->fresh(['citizen', 'serviceType', 'licenseType']);
    }

    /**
     * @param  array{email: string, name: string, phone: string, national_id: string}  $citizen
     */
    private function seedReadyFollowOn(
        User $examiner,
        string $applicationNumber,
        string $origApplicationNumber,
        string $serviceCode,
        array $citizen,
        string $licenseNumber,
        string $historyNote,
    ): LicenseApplication {
        $user = $this->upsertCitizen($citizen);
        $orig = $this->upsertApplication($user, $origApplicationNumber, ApplicationStatus::LicenseIssued);
        $orig->status = ApplicationStatus::LicenseIssued;
        $orig->approved_at = now()->subYears(3);
        $orig->issued_at = now()->subYears(3);
        $orig->current_test_type_id = null;
        $orig->save();

        $related = License::query()->updateOrCreate(
            ['license_number' => $licenseNumber],
            [
                'citizen_id' => $user->id,
                'license_type_id' => $orig->license_type_id,
                'application_id' => $orig->id,
                'status' => LicenseStatus::Active,
                'issue_date' => now()->subYears(3)->toDateString(),
                'expiry_date' => now()->addDays(30)->toDateString(),
                'issued_by' => $examiner->id,
                'deleted_at' => null,
            ]
        );

        $application = $this->upsertApplication(
            $user,
            $applicationNumber,
            ApplicationStatus::Approved,
            $serviceCode,
            'private',
            $related->id,
        );
        $this->attachApprovedDocuments($application, $examiner);
        $this->attachCompletedFee($application, $user);
        $this->clearUnpaidFines($user);

        $application->current_test_type_id = null;
        $application->status = ApplicationStatus::Approved;
        $application->approved_at = now()->subHours(8);
        $application->issued_at = null;
        $application->related_license_id = $related->id;
        $application->save();
        $this->replaceStatusHistory($application, ApplicationStatus::Approved, $examiner, $historyNote);

        return $application->fresh(['citizen', 'serviceType', 'licenseType', 'relatedLicense']);
    }

    private function finalizeTestingApp(
        LicenseApplication $application,
        User $examiner,
        string $currentTestCode,
        ApplicationStatus $status,
        string $historyNote,
    ): LicenseApplication {
        $testType = $this->testType($currentTestCode);
        $application->current_test_type_id = $testType->id;
        $application->status = $status;
        $application->approved_at = null;
        $application->issued_at = null;
        $application->save();
        $this->replaceStatusHistory($application, $status, $examiner, $historyNote);

        return $application->fresh(['citizen', 'serviceType', 'licenseType', 'currentTestType']);
    }

    private function prepareIssuancePrereqs(LicenseApplication $application, User $citizen, User $examiner): void
    {
        $this->attachApprovedDocuments($application, $examiner);
        $this->attachCompletedFee($application, $citizen);
        $this->clearUnpaidFines($citizen);
    }

    private function completeAllRequiredTests(LicenseApplication $application, User $citizen, User $examiner): void
    {
        $this->completePassedTest($application, $citizen, $examiner, 'vision', now()->subDays(3));
        $this->completePassedTest($application, $citizen, $examiner, 'theory', now()->subDays(2));
        $this->completePassedTest($application, $citizen, $examiner, 'practical', now()->subDay());
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

    private function upsertApplication(
        User $citizen,
        string $applicationNumber,
        ApplicationStatus $status,
        string $serviceCode = 'new_license',
        string $licenseTypeCode = 'private',
        ?int $relatedLicenseId = null,
    ): LicenseApplication {
        $this->resetCommitteeApplication($applicationNumber);

        $licenseType = LicenseType::query()->where('code', $licenseTypeCode)->firstOrFail();
        $serviceType = ServiceType::query()->where('code', $serviceCode)->firstOrFail();
        $submittedAt = now()->subDays(10);

        return LicenseApplication::query()->updateOrCreate(
            ['application_number' => $applicationNumber],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'related_license_id' => $relatedLicenseId,
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
            LicenseApplication::query()
                ->where('related_license_id', $license->id)
                ->update(['related_license_id' => null]);
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
        ApplicationDocument::withTrashed()->where('application_id', $application->id)->forceDelete();
        Payment::withTrashed()->where('application_id', $application->id)->forceDelete();

        if ($application->trashed()) {
            $application->restore();
        }
    }

    private function attachApprovedDocuments(LicenseApplication $application, User $reviewer): void
    {
        foreach ($this->requiredDocumentsFor($application) as $document) {
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

    private function attachPartialDocuments(LicenseApplication $application, User $reviewer): void
    {
        $required = $this->requiredDocumentsFor($application);
        $first = $required->first();
        if ($first === null) {
            return;
        }

        $path = $this->putDemoFile($application, $first);
        ApplicationDocument::withTrashed()->updateOrCreate(
            [
                'application_id' => $application->id,
                'required_document_id' => $first->id,
            ],
            [
                'file_path' => $path,
                'original_name' => $first->code.'.pdf',
                'mime_type' => 'application/pdf',
                'size' => Storage::disk('local')->size($path),
                'status' => DocumentStatus::PendingReview,
                'rejection_reason' => null,
                'rejection_reason_code' => null,
                'rejection_details' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'deleted_at' => null,
            ]
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, RequiredDocument>
     */
    private function requiredDocumentsFor(LicenseApplication $application)
    {
        return RequiredDocument::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->where(function ($q) use ($application): void {
                $q->whereNull('license_type_id')->orWhere('license_type_id', $application->license_type_id);
            })
            ->where(function ($q) use ($application): void {
                $q->whereNull('service_type_id')->orWhere('service_type_id', $application->service_type_id);
            })
            ->get();
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

    private function attachPendingFee(LicenseApplication $application, User $citizen): void
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
                'status' => PaymentStatus::Pending,
                'provider' => 'mock',
                'provider_reference' => $application->application_number.'-FEE-PENDING',
                'paid_at' => null,
                'metadata' => ['source' => 'committee_demo'],
                'failure_code' => null,
                'failure_message' => null,
                'failed_at' => null,
                'settled_obligation_key' => null,
                'active_obligation_key' => Payment::obligationKey($application->id, $fee->id),
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

    private function createUnpaidFine(User $citizen, string $reason): Fine
    {
        return Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => null,
            'amount' => 25.00,
            'currency' => strtoupper((string) config('payment.fine_currency', 'USD')),
            'reason' => $reason,
            'status' => FineStatus::Unpaid,
            'paid_at' => null,
        ]);
    }

    private function completePassedTest(
        LicenseApplication $application,
        User $citizen,
        User $examiner,
        string $testTypeCode,
        Carbon $when
    ): void {
        $this->completeAttemptedTest($application, $citizen, $examiner, $testTypeCode, TestResultStatus::Passed, 1, $when);
    }

    private function completeAttemptedTest(
        LicenseApplication $application,
        User $citizen,
        User $examiner,
        string $testTypeCode,
        TestResultStatus $result,
        int $attemptNumber,
        Carbon $when,
        AppointmentStatus $appointmentStatus = AppointmentStatus::Completed,
    ): void {
        $testType = $this->testType($testTypeCode);
        $slot = $this->slotFor($testType, preferPast: true);
        $scheduledAt = $this->scheduledAt($slot, $when);

        $resolvedStatus = $result === TestResultStatus::NoShow
            ? AppointmentStatus::NoShow
            : $appointmentStatus;

        $appointment = TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => $resolvedStatus,
            'scheduled_at' => $scheduledAt,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);

        TestResult::query()->create([
            'application_id' => $application->id,
            'test_appointment_id' => $appointment->id,
            'test_type_id' => $testType->id,
            'result' => $result,
            'attempt_number' => $attemptNumber,
            'notes' => 'نتيجة تجريبية للجنة — '.$result->value.' '.$testTypeCode.' #'.$attemptNumber,
            'recorded_by' => $examiner->id,
            'recorded_at' => $scheduledAt->copy()->addHours(2),
        ]);
    }

    private function createCancelledAppointment(
        LicenseApplication $application,
        User $citizen,
        string $testTypeCode,
        Carbon $when,
    ): TestAppointment {
        $testType = $this->testType($testTypeCode);
        $slot = $this->slotFor($testType, preferPast: true);
        $scheduledAt = $this->scheduledAt($slot, $when);

        return TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Cancelled,
            'scheduled_at' => $scheduledAt,
            'cancelled_at' => $scheduledAt->copy()->subHours(3),
            'cancellation_reason' => 'إلغاء تجريبي للجنة — عرض فلتر الملغى',
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

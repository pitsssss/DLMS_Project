<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProfileStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\Payment;
use App\Modules\Appointments\Support\SlotIdentity;
use App\Modules\Payments\Support\ApplicationFeeCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Deterministic, benchmark-only dataset for SYRTAK load tests (k6).
 *
 * Hard guards:
 * - APP_ENV must be "benchmark"
 * - DB database name must be "dlms_benchmark"
 *
 * Does not call Stripe / Gemini / FCM / mail / any network services.
 */
class BenchmarkPerformanceSeeder extends Seeder
{
    private const TARGET_DB = 'dlms_benchmark';

    private const CITIZEN_COUNT = 2000;

    private const EMPLOYEE_COUNT = 20;

    private const APPLICATION_COUNT = 5000;

    private const LICENSE_COUNT = 2000;

    private const DOCUMENT_TARGET = 15000;

    private const PAYMENT_TARGET = 4000;

    private const APPOINTMENT_TARGET = 5000;

    private const RESULT_TARGET = 4000;

    private const FINE_TARGET = 1500;

    private const NOTIFICATION_COUNT = 15000;

    private const AUDIT_LOG_COUNT = 25000;

    private const APP_HISTORY_TARGET = 15000;

    private const LICENSE_HISTORY_TARGET = 5000;

    private const CHUNK = 500;

    private const ADMIN_EMAIL = 'benchmark.admin@syrtak.local';

    private const CITIZEN_EMAIL = 'benchmark.citizen@syrtak.local';

    private const ACCOUNT_PASSWORD = 'Benchmark!2026';

    private const EPOCH = '2026-08-15 12:00:00';

    /** @var list<string> */
    private const GOVERNORATES = [
        'دمشق', 'ريف دمشق', 'حلب', 'حمص', 'حماة', 'اللاذقية', 'طرطوس',
        'إدلب', 'دير الزور', 'الرقة', 'الحسكة', 'السويداء', 'درعا', 'القنيطرة',
    ];

    private CarbonImmutable $now;

    private string $passwordHash;

    /** @var array<string, int> */
    private array $roleIds = [];

    /** @var array<string, int> */
    private array $licenseTypeIds = [];

    /** @var array<string, int> */
    private array $serviceTypeIds = [];

    /** @var array<string, int> */
    private array $testTypeIds = [];

    /** @var array<int, list<int>> service_type_id => required_document ids */
    private array $requiredDocsByService = [];

    /** @var array<string, array{id:int, amount:string, currency:string}> */
    private array $feesByCode = [];

    /** @var list<int> */
    private array $citizenIds = [];

    /** @var list<int> */
    private array $employeeIds = [];

    private int $adminId = 0;

    private int $benchmarkCitizenId = 0;

    /** @var list<int> primary license id per citizen index */
    private array $primaryLicenseIds = [];

    /** @var list<int> */
    private array $slotIds = [];

    /** @var array<int, int> slot_id => booked_count */
    private array $slotBooked = [];

    public function run(): void
    {
        $started = microtime(true);

        $this->assertBenchmarkEnvironment();
        $this->now = CarbonImmutable::parse(self::EPOCH);
        $this->passwordHash = Hash::make(self::ACCOUNT_PASSWORD);

        $this->command?->info('BenchmarkPerformanceSeeder: seeding catalog…');
        $this->seedCatalog();

        $this->loadCatalogMaps();

        $this->command?->info('BenchmarkPerformanceSeeder: employees + citizens…');
        $this->seedEmployees();
        $this->seedCitizens();

        $this->command?->info('BenchmarkPerformanceSeeder: appointment slots…');
        $this->seedAppointmentSlots();

        $this->command?->info('BenchmarkPerformanceSeeder: applications + licenses…');
        $this->seedApplicationsLicensesAndChildren();

        $this->command?->info('BenchmarkPerformanceSeeder: notifications + audit logs…');
        $this->seedNotifications();
        $this->seedAuditLogs();

        $duration = round(microtime(true) - $started, 2);
        $this->printSummary($duration);
    }

    private function assertBenchmarkEnvironment(): void
    {
        if (! app()->environment('benchmark')) {
            throw new RuntimeException(
                'BenchmarkPerformanceSeeder aborted: APP_ENV must be "benchmark" (got "'.app()->environment().'").'
            );
        }

        $database = (string) DB::connection()->getDatabaseName();
        if ($database !== self::TARGET_DB) {
            throw new RuntimeException(
                'BenchmarkPerformanceSeeder aborted: database must be "'.self::TARGET_DB.'" (got "'.$database.'").'
            );
        }
    }

    private function seedCatalog(): void
    {
        $this->call([
            RolesSeeder::class,
            PermissionsSeeder::class,
            LicenseTypesSeeder::class,
            ServiceTypesSeeder::class,
            TestTypesSeeder::class,
            RequiredDocumentsSeeder::class,
            FeesSeeder::class,
            AppointmentCentersSeeder::class,
        ]);
    }

    private function loadCatalogMaps(): void
    {
        $this->roleIds = DB::table('roles')->pluck('id', 'name')->map(fn ($id) => (int) $id)->all();
        $this->licenseTypeIds = DB::table('license_types')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $this->serviceTypeIds = DB::table('service_types')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
        $this->testTypeIds = DB::table('test_types')->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();

        foreach (['super_admin', 'admin', 'citizen', 'application_manager', 'test_employee', 'license_employee'] as $required) {
            if (! isset($this->roleIds[$required])) {
                throw new RuntimeException("Missing required role [{$required}].");
            }
        }

        foreach (['private', 'public', 'truck', 'bus'] as $code) {
            if (! isset($this->licenseTypeIds[$code])) {
                throw new RuntimeException("Missing license type [{$code}].");
            }
        }

        foreach (['new_license', 'renew_license', 'lost_replacement', 'damaged_replacement', 'license_unblock'] as $code) {
            if (! isset($this->serviceTypeIds[$code])) {
                throw new RuntimeException("Missing service type [{$code}].");
            }
        }

        foreach (['vision', 'theory', 'practical'] as $code) {
            if (! isset($this->testTypeIds[$code])) {
                throw new RuntimeException("Missing test type [{$code}].");
            }
        }

        $docs = DB::table('required_documents')
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'service_type_id']);

        foreach ($docs as $doc) {
            $sid = (int) $doc->service_type_id;
            $this->requiredDocsByService[$sid][] = (int) $doc->id;
        }

        $fees = DB::table('fees')->where('is_active', true)->get(['id', 'code', 'amount', 'currency', 'license_type_id']);
        foreach ($fees as $fee) {
            $key = (string) $fee->code;
            if ($key === 'application_fee') {
                $lt = (int) $fee->license_type_id;
                $this->feesByCode[$key.':'.$lt] = [
                    'id' => (int) $fee->id,
                    'amount' => (string) $fee->amount,
                    'currency' => (string) $fee->currency,
                ];
            } else {
                $this->feesByCode[$key] = [
                    'id' => (int) $fee->id,
                    'amount' => (string) $fee->amount,
                    'currency' => (string) $fee->currency,
                ];
            }
        }
    }

    private function seedEmployees(): void
    {
        $definitions = [
            ['email' => self::ADMIN_EMAIL, 'name' => 'Benchmark Admin', 'role' => 'admin', 'type' => UserType::Admin->value, 'phone' => '0900000001'],
            ['email' => 'bm.emp.profile@syrtak.local', 'name' => 'Benchmark Profile Reviewer', 'role' => 'profile_document_reviewer', 'type' => UserType::Employee->value, 'phone' => '0900000002'],
            ['email' => 'bm.emp.fines@syrtak.local', 'name' => 'Benchmark Fines Employee', 'role' => 'fines_employee', 'type' => UserType::Employee->value, 'phone' => '0900000003'],
            ['email' => 'bm.emp.audit@syrtak.local', 'name' => 'Benchmark Audit Employee', 'role' => 'audit_employee', 'type' => UserType::Employee->value, 'phone' => '0900000004'],
            ['email' => 'bm.emp.reports@syrtak.local', 'name' => 'Benchmark Reports Employee', 'role' => 'reports_employee', 'type' => UserType::Employee->value, 'phone' => '0900000005'],
            ['email' => 'bm.emp.settings@syrtak.local', 'name' => 'Benchmark Settings Employee', 'role' => 'settings_employee', 'type' => UserType::Employee->value, 'phone' => '0900000006'],
            ['email' => 'bm.emp.applications@syrtak.local', 'name' => 'Benchmark Application Manager', 'role' => 'application_manager', 'type' => UserType::Employee->value, 'phone' => '0900000007'],
            ['email' => 'bm.emp.tests@syrtak.local', 'name' => 'Benchmark Test Employee', 'role' => 'test_employee', 'type' => UserType::Employee->value, 'phone' => '0900000008'],
            ['email' => 'bm.emp.licenses@syrtak.local', 'name' => 'Benchmark License Employee', 'role' => 'license_employee', 'type' => UserType::Employee->value, 'phone' => '0900000009'],
            ['email' => 'bm.emp.payments@syrtak.local', 'name' => 'Benchmark Payment Employee', 'role' => 'payment_employee', 'type' => UserType::Employee->value, 'phone' => '0900000010'],
        ];

        $employeeRoleId = $this->roleIds['employee'] ?? $this->roleIds['application_manager'];
        for ($i = count($definitions); $i < self::EMPLOYEE_COUNT; $i++) {
            $n = $i + 1;
            $definitions[] = [
                'email' => sprintf('bm.emp.%02d@syrtak.local', $n),
                'name' => sprintf('Benchmark Employee %02d', $n),
                'role' => 'application_manager',
                'type' => UserType::Employee->value,
                'phone' => sprintf('09000000%02d', $n),
            ];
        }

        $startId = $this->nextId('users');
        $rows = [];
        foreach ($definitions as $i => $def) {
            $roleId = $this->roleIds[$def['role']] ?? $employeeRoleId;
            $created = $this->ts(-90 + ($i % 30));
            $rows[] = [
                'name' => $def['name'],
                'phone' => $def['phone'],
                'national_id' => null,
                'email' => $def['email'],
                'email_verified_at' => $created,
                'password' => $this->passwordHash,
                'role_id' => $roleId,
                'user_type' => $def['type'],
                'birth_date' => null,
                'governorate' => 'دمشق',
                'address' => 'Benchmark HQ',
                'language' => 'ar',
                'theme' => 'system',
                'profile_completed' => 1,
                'profile_status' => ProfileStatus::Approved->value,
                'profile_rejection_reason' => null,
                'profile_reviewed_by' => null,
                'profile_reviewed_at' => $created,
                'profile_submitted_at' => $created,
                'is_active' => 1,
                'phone_verified_at' => $created,
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }

        $this->insertChunked('users', $rows);

        $this->employeeIds = range($startId, $startId + count($definitions) - 1);
        $this->adminId = $this->employeeIds[0];
    }

    private function seedCitizens(): void
    {
        $citizenRoleId = $this->roleIds['citizen'];
        $startId = $this->nextId('users');
        $rows = [];

        for ($i = 0; $i < self::CITIZEN_COUNT; $i++) {
            $created = $this->ts(-180 + ($i % 150));
            $email = $i === 0
                ? self::CITIZEN_EMAIL
                : sprintf('bm.citizen.%04d@syrtak.local', $i);

            $rows[] = [
                'name' => $i === 0 ? 'Benchmark Citizen' : sprintf('مواطن تجريبي %04d', $i),
                'phone' => sprintf('091%07d', $i),
                'national_id' => sprintf('2%010d', 1000000000 + $i),
                'email' => $email,
                'email_verified_at' => $created,
                'password' => $this->passwordHash,
                'role_id' => $citizenRoleId,
                'user_type' => UserType::Citizen->value,
                'birth_date' => sprintf('19%02d-%02d-%02d', 70 + ($i % 30), 1 + ($i % 12), 1 + ($i % 28)),
                'governorate' => self::GOVERNORATES[$i % count(self::GOVERNORATES)],
                'address' => sprintf('حي تجريبي %d — شارع %d — بناء %d', ($i % 40) + 1, ($i % 80) + 1, ($i % 20) + 1),
                'language' => $i % 5 === 0 ? 'en' : 'ar',
                'theme' => 'system',
                'profile_completed' => 1,
                'profile_status' => ProfileStatus::Approved->value,
                'profile_rejection_reason' => null,
                'profile_reviewed_by' => $this->adminId,
                'profile_reviewed_at' => $created,
                'profile_submitted_at' => $this->shift($created, -1),
                'is_active' => 1,
                'phone_verified_at' => $created,
                'created_at' => $created,
                'updated_at' => $created,
            ];

            if (count($rows) >= self::CHUNK) {
                $this->insertChunked('users', $rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->insertChunked('users', $rows);
        }

        $this->citizenIds = range($startId, $startId + self::CITIZEN_COUNT - 1);
        $this->benchmarkCitizenId = $this->citizenIds[0];
    }

    private function seedAppointmentSlots(): void
    {
        $centerId = (int) DB::table('appointment_centers')->orderBy('id')->value('id');
        if ($centerId <= 0) {
            throw new RuntimeException('No appointment center found after catalog seed.');
        }

        $startId = $this->nextId('appointment_slots');
        $rows = [];
        $slotIndex = 0;

        // Fixed historical + near-term calendar (deterministic, not BusinessClock::now()).
        $dayCount = 120;
        $times = [
            ['09:00:00', '11:00:00'],
            ['14:00:00', '16:00:00'],
        ];

        foreach ($this->testTypeIds as $testTypeId) {
            for ($d = 0; $d < $dayCount; $d++) {
                $date = $this->now->subDays(90)->addDays($d)->toDateString();
                foreach ($times as [$start, $end]) {
                    $created = $this->ts(-100);
                    $rows[] = [
                        'test_type_id' => $testTypeId,
                        'appointment_center_id' => $centerId,
                        'identity_key' => SlotIdentity::buildKey((int) $testTypeId, $centerId, $date, $start, $end),
                        'date' => $date,
                        'start_time' => $start,
                        'end_time' => $end,
                        'capacity' => 80,
                        'booked_count' => 0,
                        'location' => 'المركز الرئيسي',
                        'is_active' => 1,
                        'created_by' => $this->adminId,
                        'updated_by' => $this->adminId,
                        'deactivated_at' => null,
                        'deactivated_by' => null,
                        'version' => 1,
                        'created_at' => $created,
                        'updated_at' => $created,
                    ];
                    $slotIndex++;

                    if (count($rows) >= self::CHUNK) {
                        $this->insertChunked('appointment_slots', $rows);
                        $rows = [];
                    }
                }
            }
        }

        if ($rows !== []) {
            $this->insertChunked('appointment_slots', $rows);
        }

        $this->slotIds = range($startId, $startId + $slotIndex - 1);
        foreach ($this->slotIds as $slotId) {
            $this->slotBooked[$slotId] = 0;
        }
    }

    private function seedApplicationsLicensesAndChildren(): void
    {
        $newLicenseServiceId = $this->serviceTypeIds['new_license'];
        $renewServiceId = $this->serviceTypeIds['renew_license'];
        $lostServiceId = $this->serviceTypeIds['lost_replacement'];
        $damagedServiceId = $this->serviceTypeIds['damaged_replacement'];
        $unblockServiceId = $this->serviceTypeIds['license_unblock'];
        $privateTypeId = $this->licenseTypeIds['private'];
        $licenseTypeCycle = [
            $this->licenseTypeIds['private'],
            $this->licenseTypeIds['public'],
            $this->licenseTypeIds['truck'],
            $this->licenseTypeIds['bus'],
        ];

        $visionId = $this->testTypeIds['vision'];
        $theoryId = $this->testTypeIds['theory'];
        $practicalId = $this->testTypeIds['practical'];
        $orderedTests = [$visionId, $theoryId, $practicalId];

        // Phase A: new_license → license_issued (1334) for tests + most licenses
        $issuedNewCount = 1334;
        // Phase B: replacement/renew issued without tests (666) → reach 2000 licenses
        $issuedSecondaryCount = self::LICENSE_COUNT - $issuedNewCount; // 666
        // Phase C: remaining applications with mixed statuses
        $remaining = self::APPLICATION_COUNT - self::LICENSE_COUNT; // 3000

        $appStartId = $this->nextId('license_applications');
        $appRows = [];
        $appMeta = []; // index => meta for child seeding

        // --- Phase A ---
        for ($i = 0; $i < $issuedNewCount; $i++) {
            $citizenId = $this->citizenIds[$i];
            $submitted = $this->ts(-120 + ($i % 100));
            $issuedAt = $this->shift($submitted, 20 + ($i % 10));
            $appRows[] = $this->applicationRow(
                $i,
                $citizenId,
                $privateTypeId,
                $newLicenseServiceId,
                null,
                ApplicationStatus::LicenseIssued->value,
                $practicalId,
                null,
                $submitted,
                $this->shift($submitted, 18),
                $issuedAt
            );
            $appMeta[$i] = [
                'status' => ApplicationStatus::LicenseIssued->value,
                'service' => 'new_license',
                'service_id' => $newLicenseServiceId,
                'license_type_id' => $privateTypeId,
                'citizen_id' => $citizenId,
                'citizen_index' => $i,
                'submitted_at' => $submitted,
                'issued_at' => $issuedAt,
                'needs_tests' => true,
                'produces_license' => true,
            ];
        }

        // --- Phase B: secondary issued (related license filled after phase A licenses exist) ---
        $secondaryServices = [
            ['code' => 'renew_license', 'id' => $renewServiceId],
            ['code' => 'lost_replacement', 'id' => $lostServiceId],
            ['code' => 'damaged_replacement', 'id' => $damagedServiceId],
        ];

        for ($i = 0; $i < $issuedSecondaryCount; $i++) {
            $global = $issuedNewCount + $i;
            $citizenIndex = $issuedNewCount + $i; // citizens 1334..1999
            $citizenId = $this->citizenIds[$citizenIndex];
            $svc = $secondaryServices[$i % 3];
            $submitted = $this->ts(-90 + ($i % 80));
            $issuedAt = $this->shift($submitted, 10 + ($i % 5));
            // related_license_id placeholder 0 — patched after primary licenses for these citizens
            $appRows[] = $this->applicationRow(
                $global,
                $citizenId,
                $privateTypeId,
                $svc['id'],
                null,
                ApplicationStatus::LicenseIssued->value,
                null,
                null,
                $submitted,
                $this->shift($submitted, 8),
                $issuedAt
            );
            $appMeta[$global] = [
                'status' => ApplicationStatus::LicenseIssued->value,
                'service' => $svc['code'],
                'service_id' => $svc['id'],
                'license_type_id' => $privateTypeId,
                'citizen_id' => $citizenId,
                'citizen_index' => $citizenIndex,
                'submitted_at' => $submitted,
                'issued_at' => $issuedAt,
                'needs_tests' => false,
                'produces_license' => true,
                'needs_related_primary_of_same_citizen' => true,
            ];
        }

        // Insert phase A+B apps first without related_license (phase B related patched later)
        $this->insertChunked('license_applications', $appRows);
        $appRows = [];

        // Create primary licenses for phase A (citizens 0..1333)
        $licenseStartId = $this->nextId('licenses');
        $licenseRows = [];
        $licenseHistoryRows = [];
        $this->primaryLicenseIds = array_fill(0, self::CITIZEN_COUNT, 0);

        for ($i = 0; $i < $issuedNewCount; $i++) {
            $appId = $appStartId + $i;
            $citizenId = $this->citizenIds[$i];
            $issuedAt = $appMeta[$i]['issued_at'];
            $issueDate = substr($issuedAt, 0, 10);
            $expiryDate = CarbonImmutable::parse($issueDate)->addYears(5)->toDateString();
            $status = LicenseStatus::Active->value;
            // Block a slice later used by unblock applications
            if ($i >= 900 && $i < 1300) {
                $status = LicenseStatus::Blocked->value;
            }

            $licenseRows[] = [
                'license_number' => sprintf('LIC-BM-%07d', $i + 1),
                'citizen_id' => $citizenId,
                'license_type_id' => $privateTypeId,
                'application_id' => $appId,
                'issued_by' => $this->employeeIds[8 % count($this->employeeIds)],
                'previous_license_id' => null,
                'status' => $status,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'blocked_at' => $status === LicenseStatus::Blocked->value ? $this->shift($issuedAt, 30) : null,
                'blocked_by' => $status === LicenseStatus::Blocked->value ? $this->adminId : null,
                'block_reason' => $status === LicenseStatus::Blocked->value ? 'benchmark block sample' : null,
                'verification_token' => sprintf('bmvt%060d', $i + 1),
                'printed_at' => $i % 3 === 0 ? $this->shift($issuedAt, 1) : null,
                'printed_by' => $i % 3 === 0 ? $this->employeeIds[8 % count($this->employeeIds)] : null,
                'print_count' => $i % 3 === 0 ? 1 + ($i % 3) : 0,
                'created_at' => $issuedAt,
                'updated_at' => $issuedAt,
            ];

            $licenseId = $licenseStartId + $i;
            $this->primaryLicenseIds[$i] = $licenseId;

            $licenseHistoryRows[] = [
                'license_id' => $licenseId,
                'from_status' => null,
                'to_status' => LicenseStatus::Active->value,
                'action' => 'license.issued',
                'reason' => null,
                'performed_by' => $this->employeeIds[8 % count($this->employeeIds)],
                'source' => 'benchmark_seeder',
                'metadata' => null,
                'created_at' => $issuedAt,
                'updated_at' => $issuedAt,
            ];
            if ($status === LicenseStatus::Blocked->value) {
                $licenseHistoryRows[] = [
                    'license_id' => $licenseId,
                    'from_status' => LicenseStatus::Active->value,
                    'to_status' => LicenseStatus::Blocked->value,
                    'action' => 'license.blocked',
                    'reason' => 'benchmark block sample',
                    'performed_by' => $this->adminId,
                    'source' => 'benchmark_seeder',
                    'metadata' => null,
                    'created_at' => $this->shift($issuedAt, 30),
                    'updated_at' => $this->shift($issuedAt, 30),
                ];
            }
        }

        $this->insertChunked('licenses', $licenseRows);
        $licenseRows = [];

        // Phase B citizens also need a primary license first (new_license issued) before secondary issued apps.
        // They already have secondary apps inserted; create a lightweight primary new_license+license for them.
        // To keep APPLICATION_COUNT exact, primary for 1334..1999 was NOT separate apps —
        // instead related_license for phase B points at phase A licenses of other citizens? NO — must own license.
        // Fix: for citizens 1334..1999, create primary licenses tied to... we need applications.
        // Re-plan phase B: use citizens 0..665 who already have licenses from phase A for secondary issued apps.
        // Delete incorrect assumption and patch related_license_id now.

        // Patch phase B apps: citizen_index should map to citizens who already have licenses (0..665)
        // Re-write citizen_id + related_license on those application rows.
        for ($i = 0; $i < $issuedSecondaryCount; $i++) {
            $global = $issuedNewCount + $i;
            $appId = $appStartId + $global;
            $ownerIndex = $i % $issuedNewCount; // 0..1333
            $citizenId = $this->citizenIds[$ownerIndex];
            $relatedLicenseId = $this->primaryLicenseIds[$ownerIndex];
            $svc = $secondaryServices[$i % 3];

            DB::table('license_applications')->where('id', $appId)->update([
                'citizen_id' => $citizenId,
                'related_license_id' => $relatedLicenseId,
                'service_type_id' => $svc['id'],
                'license_type_id' => $privateTypeId,
            ]);

            $appMeta[$global]['citizen_id'] = $citizenId;
            $appMeta[$global]['citizen_index'] = $ownerIndex;
            $appMeta[$global]['service'] = $svc['code'];
            $appMeta[$global]['service_id'] = $svc['id'];
            $appMeta[$global]['related_license_id'] = $relatedLicenseId;
        }

        // Create secondary licenses (666) from phase B apps
        $secondaryLicenseStart = $this->nextId('licenses');
        for ($i = 0; $i < $issuedSecondaryCount; $i++) {
            $global = $issuedNewCount + $i;
            $appId = $appStartId + $global;
            $meta = $appMeta[$global];
            $issuedAt = $meta['issued_at'];
            $issueDate = substr($issuedAt, 0, 10);
            $previousId = $meta['related_license_id'];

            $licenseRows[] = [
                'license_number' => sprintf('LIC-BM-%07d', $issuedNewCount + $i + 1),
                'citizen_id' => $meta['citizen_id'],
                'license_type_id' => $privateTypeId,
                'application_id' => $appId,
                'issued_by' => $this->employeeIds[8 % count($this->employeeIds)],
                'previous_license_id' => $previousId,
                'status' => LicenseStatus::Active->value,
                'issue_date' => $issueDate,
                'expiry_date' => CarbonImmutable::parse($issueDate)->addYears(5)->toDateString(),
                'blocked_at' => null,
                'blocked_by' => null,
                'block_reason' => null,
                'verification_token' => sprintf('bmvt%060d', $issuedNewCount + $i + 1),
                'printed_at' => null,
                'printed_by' => null,
                'print_count' => 0,
                'created_at' => $issuedAt,
                'updated_at' => $issuedAt,
            ];

            $licenseId = $secondaryLicenseStart + $i;
            $licenseHistoryRows[] = [
                'license_id' => $licenseId,
                'from_status' => null,
                'to_status' => LicenseStatus::Active->value,
                'action' => 'license.issued',
                'reason' => null,
                'performed_by' => $this->employeeIds[8 % count($this->employeeIds)],
                'source' => 'benchmark_seeder',
                'metadata' => null,
                'created_at' => $issuedAt,
                'updated_at' => $issuedAt,
            ];

            // Mark previous as renewed for a subset
            if ($i % 4 === 0) {
                DB::table('licenses')->where('id', $previousId)->update([
                    'status' => LicenseStatus::Renewed->value,
                    'updated_at' => $issuedAt,
                ]);
                $licenseHistoryRows[] = [
                    'license_id' => $previousId,
                    'from_status' => LicenseStatus::Active->value,
                    'to_status' => LicenseStatus::Renewed->value,
                    'action' => 'license.renewed',
                    'reason' => 'replaced by benchmark renewal/replacement',
                    'performed_by' => $this->employeeIds[8 % count($this->employeeIds)],
                    'source' => 'benchmark_seeder',
                    'metadata' => json_encode(['new_license_id' => $licenseId], JSON_UNESCAPED_UNICODE),
                    'created_at' => $issuedAt,
                    'updated_at' => $issuedAt,
                ];
            }
        }
        $this->insertChunked('licenses', $licenseRows);

        // --- Phase C: remaining mixed applications ---
        $mixedStatuses = [
            ApplicationStatus::Draft->value,
            ApplicationStatus::DocumentsUnderReview->value,
            ApplicationStatus::DocumentsRejected->value,
            ApplicationStatus::PaymentPending->value,
            ApplicationStatus::PaymentCompleted->value,
            ApplicationStatus::AppointmentPending->value,
            ApplicationStatus::InTesting->value,
            ApplicationStatus::WaitingRetest->value,
            ApplicationStatus::Approved->value,
            ApplicationStatus::Rejected->value,
            ApplicationStatus::Cancelled->value,
            ApplicationStatus::AdministrativeReview->value,
        ];

        $phaseCStart = self::LICENSE_COUNT;
        for ($i = 0; $i < $remaining; $i++) {
            $global = $phaseCStart + $i;
            $citizenIndex = $i % self::CITIZEN_COUNT;
            $citizenId = $this->citizenIds[$citizenIndex];
            $bucket = $i % 100;
            $submitted = $this->ts(-100 + ($i % 95));

            if ($bucket < 35) {
                // new_license pipeline (tests allowed); avoid duplicate active private by using cycling types / terminal statuses
                $serviceId = $newLicenseServiceId;
                $serviceCode = 'new_license';
                $licenseTypeId = $licenseTypeCycle[($i + 1) % count($licenseTypeCycle)];
                $related = null;
                $needsTests = true;
                $status = $mixedStatuses[$i % count($mixedStatuses)];
                // Prefer non-issued for new_license extras
                if ($status === ApplicationStatus::LicenseIssued->value) {
                    $status = ApplicationStatus::InTesting->value;
                }
            } elseif ($bucket < 55) {
                $serviceId = $renewServiceId;
                $serviceCode = 'renew_license';
                $licenseTypeId = $privateTypeId;
                $related = $this->primaryLicenseIds[$citizenIndex] ?: $this->primaryLicenseIds[$citizenIndex % $issuedNewCount];
                $needsTests = false;
                $status = $mixedStatuses[$i % 8]; // early/mid statuses
            } elseif ($bucket < 70) {
                $serviceId = $lostServiceId;
                $serviceCode = 'lost_replacement';
                $licenseTypeId = $privateTypeId;
                $related = $this->primaryLicenseIds[$citizenIndex] ?: $this->primaryLicenseIds[$citizenIndex % $issuedNewCount];
                $needsTests = false;
                $status = $mixedStatuses[($i + 2) % 8];
            } elseif ($bucket < 85) {
                $serviceId = $damagedServiceId;
                $serviceCode = 'damaged_replacement';
                $licenseTypeId = $privateTypeId;
                $related = $this->primaryLicenseIds[$citizenIndex] ?: $this->primaryLicenseIds[$citizenIndex % $issuedNewCount];
                $needsTests = false;
                $status = $mixedStatuses[($i + 3) % 8];
            } else {
                $serviceId = $unblockServiceId;
                $serviceCode = 'license_unblock';
                $licenseTypeId = $privateTypeId;
                // Prefer blocked licenses when available
                $blockedOwner = 900 + ($i % 400);
                if ($blockedOwner >= $issuedNewCount) {
                    $blockedOwner = 900 + ($i % max(1, min(400, $issuedNewCount - 900)));
                }
                $related = $this->primaryLicenseIds[$blockedOwner];
                $citizenId = $this->citizenIds[$blockedOwner];
                $citizenIndex = $blockedOwner;
                $needsTests = false;
                $status = [
                    ApplicationStatus::DocumentsUnderReview->value,
                    ApplicationStatus::PaymentPending->value,
                    ApplicationStatus::PaymentCompleted->value,
                    ApplicationStatus::AdministrativeReview->value,
                    ApplicationStatus::Approved->value,
                    ApplicationStatus::Rejected->value,
                ][$i % 6];
            }

            // Ensure citizens without primary license (1334+) get related from modulo of issued owners
            if ($related === 0 || $related === null) {
                if ($serviceCode !== 'new_license') {
                    $owner = $citizenIndex % $issuedNewCount;
                    $related = $this->primaryLicenseIds[$owner];
                    $citizenId = $this->citizenIds[$owner];
                    $citizenIndex = $owner;
                } else {
                    $related = null;
                }
            }

            $currentTest = null;
            if ($needsTests && in_array($status, [
                ApplicationStatus::AppointmentPending->value,
                ApplicationStatus::InTesting->value,
                ApplicationStatus::WaitingRetest->value,
                ApplicationStatus::Approved->value,
            ], true)) {
                $currentTest = $orderedTests[$i % 3];
            }

            $approvedAt = in_array($status, [
                ApplicationStatus::Approved->value,
                ApplicationStatus::AdministrativeReview->value,
            ], true) ? $this->shift($submitted, 15) : null;

            $rejection = in_array($status, [
                ApplicationStatus::Rejected->value,
                ApplicationStatus::DocumentsRejected->value,
            ], true) ? 'benchmark rejection sample' : null;

            $appRows[] = $this->applicationRow(
                $global,
                $citizenId,
                $licenseTypeId,
                $serviceId,
                $related,
                $status,
                $currentTest,
                $rejection,
                in_array($status, [ApplicationStatus::Draft->value], true) ? null : $submitted,
                $approvedAt,
                null
            );

            $appMeta[$global] = [
                'status' => $status,
                'service' => $serviceCode,
                'service_id' => $serviceId,
                'license_type_id' => $licenseTypeId,
                'citizen_id' => $citizenId,
                'citizen_index' => $citizenIndex,
                'submitted_at' => $submitted,
                'issued_at' => null,
                'needs_tests' => $needsTests,
                'produces_license' => false,
                'related_license_id' => $related,
            ];

            if (count($appRows) >= self::CHUNK) {
                $this->insertChunked('license_applications', $appRows);
                $appRows = [];
            }
        }
        if ($appRows !== []) {
            $this->insertChunked('license_applications', $appRows);
        }

        // Ensure primaryLicenseIds for citizens without phase-A license still usable via modulo owners
        for ($c = 0; $c < self::CITIZEN_COUNT; $c++) {
            if (($this->primaryLicenseIds[$c] ?? 0) === 0) {
                $this->primaryLicenseIds[$c] = $this->primaryLicenseIds[$c % $issuedNewCount];
            }
        }

        $this->seedDocumentsPaymentsAppointmentsResultsHistories(
            $appStartId,
            $appMeta,
            $orderedTests,
            $licenseHistoryRows
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $appMeta
     * @param  list<int>  $orderedTests
     * @param  list<array<string, mixed>>  $licenseHistoryRows
     */
    private function seedDocumentsPaymentsAppointmentsResultsHistories(
        int $appStartId,
        array $appMeta,
        array $orderedTests,
        array $licenseHistoryRows,
    ): void {
        $docRows = [];
        $paymentRows = [];
        $appointmentRows = [];
        $resultRows = [];
        $appHistoryRows = [];
        $paymentSeq = 0;
        $appointmentSeq = 0;
        $resultSeq = 0;
        $docSeq = 0;

        $reviewerId = $this->employeeIds[1 % count($this->employeeIds)];
        $testerId = $this->employeeIds[7 % count($this->employeeIds)];

        $appointmentStartId = $this->nextId('test_appointments');

        foreach ($appMeta as $index => $meta) {
            $appId = $appStartId + $index;
            $status = (string) $meta['status'];
            $serviceId = (int) $meta['service_id'];
            $citizenId = (int) $meta['citizen_id'];
            $submitted = (string) ($meta['submitted_at'] ?? $this->ts(-30));
            $needsTests = (bool) $meta['needs_tests'];
            $licenseTypeId = (int) $meta['license_type_id'];

            // Status history (~3 per app → ~15000)
            $path = $this->statusPath($status);
            $prev = null;
            $stepAt = $this->shift($submitted, -2);
            foreach ($path as $stepIndex => $newStatus) {
                $stepAt = $this->shift($stepAt, $stepIndex === 0 ? 0 : 1);
                $appHistoryRows[] = [
                    'application_id' => $appId,
                    'old_status' => $prev,
                    'new_status' => $newStatus,
                    'changed_by' => $stepIndex === 0 ? $citizenId : $this->employeeIds[$stepIndex % count($this->employeeIds)],
                    'reason' => null,
                    'notes' => 'benchmark',
                    'created_at' => $stepAt,
                    'updated_at' => $stepAt,
                ];
                $prev = $newStatus;
                if (count($appHistoryRows) >= self::CHUNK) {
                    $this->insertChunked('application_status_histories', $appHistoryRows);
                    $appHistoryRows = [];
                }
            }

            // Documents
            $required = $this->requiredDocsByService[$serviceId] ?? [];
            if ($required !== []) {
                // Cap docs to help land near 15k: use all required, but for surplus new_license trim to 3
                if ((string) $meta['service'] === 'new_license' && count($required) > 3 && $index % 5 !== 0) {
                    $required = array_slice($required, 0, 3);
                }

                foreach ($required as $reqId) {
                    $docStatus = $this->documentStatusForApplication($status, $docSeq);
                    $reviewedAt = in_array($docStatus, [DocumentStatus::Approved->value, DocumentStatus::Rejected->value], true)
                        ? $this->shift($submitted, 2)
                        : null;
                    $docRows[] = [
                        'application_id' => $appId,
                        'required_document_id' => $reqId,
                        'file_path' => sprintf('benchmark/documents/%d/%d.pdf', $appId, $reqId),
                        'original_name' => sprintf('doc-%d-%d.pdf', $appId, $reqId),
                        'mime_type' => 'application/pdf',
                        'size' => 50_000 + ($docSeq % 40_000),
                        'status' => $docStatus,
                        'rejection_reason' => $docStatus === DocumentStatus::Rejected->value ? 'unclear_document' : null,
                        'rejection_reason_code' => $docStatus === DocumentStatus::Rejected->value ? 'unclear_document' : null,
                        'rejection_details' => null,
                        'reviewed_by' => $reviewedAt ? $reviewerId : null,
                        'reviewed_at' => $reviewedAt,
                        'created_at' => $submitted,
                        'updated_at' => $reviewedAt ?? $submitted,
                    ];
                    $docSeq++;
                    if (count($docRows) >= self::CHUNK) {
                        $this->insertChunked('application_documents', $docRows);
                        $docRows = [];
                    }
                }
            }

            // Payments (application fee) for statuses at/after payment
            if ($this->applicationNeedsPaymentRow($status)) {
                $fee = $this->resolveFee((string) $meta['service'], $licenseTypeId);
                $payStatus = $this->paymentStatusForApplication($status, $paymentSeq);
                $paidAt = $payStatus === PaymentStatus::Completed->value ? $this->shift($submitted, 5) : null;
                $failedAt = $payStatus === PaymentStatus::Failed->value ? $this->shift($submitted, 5) : null;
                $obligation = Payment::obligationKey($appId, $fee['id']);

                $paymentRows[] = [
                    'payment_number' => sprintf('PAY-BM-%08d', ++$paymentSeq),
                    'user_id' => $citizenId,
                    'application_id' => $appId,
                    'fine_id' => null,
                    'fee_id' => $fee['id'],
                    'payable_type' => null,
                    'payable_id' => null,
                    'amount' => $fee['amount'],
                    'currency' => $fee['currency'] ?: ApplicationFeeCatalog::CURRENCY,
                    'status' => $payStatus,
                    'provider' => 'mock',
                    'provider_reference' => $payStatus === PaymentStatus::Completed->value
                        ? sprintf('mock_bm_%08d', $paymentSeq)
                        : null,
                    'paid_at' => $paidAt,
                    'metadata' => json_encode(['source' => 'benchmark_seeder'], JSON_UNESCAPED_UNICODE),
                    'failure_code' => $payStatus === PaymentStatus::Failed->value ? 'card_declined' : null,
                    'failure_message' => $payStatus === PaymentStatus::Failed->value ? 'benchmark failed payment' : null,
                    'failed_at' => $failedAt,
                    'last_verified_at' => $paidAt,
                    'settled_obligation_key' => $payStatus === PaymentStatus::Completed->value ? $obligation : null,
                    'active_obligation_key' => in_array($payStatus, [
                        PaymentStatus::Pending->value,
                        PaymentStatus::UnderVerification->value,
                    ], true) ? $obligation : null,
                    'created_at' => $this->shift($submitted, 4),
                    'updated_at' => $paidAt ?? $failedAt ?? $this->shift($submitted, 4),
                ];

                if (count($paymentRows) >= self::CHUNK) {
                    $this->insertChunked('payments', $paymentRows);
                    $paymentRows = [];
                }
            }

            // Test appointments / results for new_license only
            if ($needsTests && $this->applicationNeedsAppointments($status) && $appointmentSeq < self::APPOINTMENT_TARGET) {
                $testsForApp = $this->testsForStatus($status, $orderedTests);
                foreach ($testsForApp as $tIndex => $testTypeId) {
                    if ($appointmentSeq >= self::APPOINTMENT_TARGET) {
                        break;
                    }
                    $slotId = $this->pickSlot($appointmentSeq);
                    $scheduled = $this->shift($submitted, 7 + $tIndex);
                    $apptStatus = $this->appointmentStatusFor($status, $tIndex, count($testsForApp));
                    $appointmentRows[] = [
                        'application_id' => $appId,
                        'citizen_id' => $citizenId,
                        'appointment_slot_id' => $slotId,
                        'test_type_id' => $testTypeId,
                        'status' => $apptStatus,
                        'scheduled_at' => $scheduled,
                        'cancelled_at' => $apptStatus === AppointmentStatus::Cancelled->value ? $this->shift($scheduled, 1) : null,
                        'cancellation_reason' => $apptStatus === AppointmentStatus::Cancelled->value ? 'benchmark cancel' : null,
                        'created_at' => $this->shift($submitted, 6),
                        'updated_at' => $scheduled,
                    ];
                    $this->slotBooked[$slotId] = ($this->slotBooked[$slotId] ?? 0) + 1;

                    $apptId = $appointmentStartId + $appointmentSeq;
                    $appointmentSeq++;

                    if ($apptStatus === AppointmentStatus::Completed->value && $resultSeq < self::RESULT_TARGET) {
                        $result = $status === ApplicationStatus::WaitingRetest->value && $tIndex === count($testsForApp) - 1
                            ? TestResultStatus::Failed->value
                            : TestResultStatus::Passed->value;
                        $recordedAt = $this->shift($scheduled, 1);
                        $resultRows[] = [
                            'application_id' => $appId,
                            'test_appointment_id' => $apptId,
                            'test_type_id' => $testTypeId,
                            'result' => $result,
                            'attempt_number' => 1,
                            'notes' => 'benchmark',
                            'recorded_by' => $testerId,
                            'recorded_at' => $recordedAt,
                            'created_at' => $recordedAt,
                            'updated_at' => $recordedAt,
                        ];
                        $resultSeq++;
                    }

                    if (count($appointmentRows) >= self::CHUNK) {
                        $this->insertChunked('test_appointments', $appointmentRows);
                        $appointmentRows = [];
                    }
                    if (count($resultRows) >= self::CHUNK) {
                        if ($appointmentRows !== []) {
                            $this->insertChunked('test_appointments', $appointmentRows);
                            $appointmentRows = [];
                        }
                        $this->insertChunked('test_results', $resultRows);
                        $resultRows = [];
                    }
                }
            }
        }

        if ($docRows !== []) {
            $this->insertChunked('application_documents', $docRows);
        }
        if ($paymentRows !== []) {
            $this->insertChunked('payments', $paymentRows);
        }
        if ($appointmentRows !== []) {
            $this->insertChunked('test_appointments', $appointmentRows);
        }
        if ($resultRows !== []) {
            $this->insertChunked('test_results', $resultRows);
        }
        if ($appHistoryRows !== []) {
            $this->insertChunked('application_status_histories', $appHistoryRows);
        }

        // Pad appointments/results if slightly under target using existing issued apps
        $this->padAppointmentsAndResults($appStartId, $appMeta, $orderedTests);

        // Update slot booked_count
        foreach (array_chunk($this->slotBooked, self::CHUNK, true) as $chunk) {
            foreach ($chunk as $slotId => $count) {
                if ($count > 0) {
                    DB::table('appointment_slots')->where('id', $slotId)->update(['booked_count' => $count]);
                }
            }
        }

        // Fines + fine payments
        $this->seedFinesAndFinePayments($paymentSeq);

        // License status histories (already built for issued) + pad to ~5000
        if ($licenseHistoryRows !== []) {
            $this->insertChunked('license_status_histories', $licenseHistoryRows);
        }
        $this->padLicenseHistories(count($licenseHistoryRows));

        // Pad application histories if needed
        $this->padApplicationHistories($appStartId);

        // Pad documents/payments slightly if under targets
        $this->padDocumentsIfNeeded($appStartId, $appMeta);
        $this->padPaymentsIfNeeded($appStartId, $appMeta, $paymentSeq);
    }

    /**
     * @param  array<int, array<string, mixed>>  $appMeta
     * @param  list<int>  $orderedTests
     */
    private function padAppointmentsAndResults(
        int $appStartId,
        array $appMeta,
        array $orderedTests,
    ): void {
        $testerId = $this->employeeIds[7 % count($this->employeeIds)];

        $currentAppts = (int) DB::table('test_appointments')->count();
        if ($currentAppts < self::APPOINTMENT_TARGET) {
            $need = self::APPOINTMENT_TARGET - $currentAppts;
            $rows = [];
            for ($n = 0; $n < $need; $n++) {
                $meta = $appMeta[$n % count($appMeta)];
                $slotId = $this->pickSlot($currentAppts + $n);
                $rows[] = [
                    'application_id' => $appStartId + ($n % self::APPLICATION_COUNT),
                    'citizen_id' => (int) $meta['citizen_id'],
                    'appointment_slot_id' => $slotId,
                    'test_type_id' => $orderedTests[$n % count($orderedTests)],
                    'status' => $n % 4 === 0
                        ? AppointmentStatus::Booked->value
                        : AppointmentStatus::Completed->value,
                    'scheduled_at' => $this->ts(-10 + ($n % 8)),
                    'cancelled_at' => null,
                    'cancellation_reason' => null,
                    'created_at' => $this->ts(-12),
                    'updated_at' => $this->ts(-10),
                ];
                $this->slotBooked[$slotId] = ($this->slotBooked[$slotId] ?? 0) + 1;

                if (count($rows) >= self::CHUNK) {
                    $this->insertChunked('test_appointments', $rows);
                    $rows = [];
                }
            }
            if ($rows !== []) {
                $this->insertChunked('test_appointments', $rows);
            }
        }

        $currentResults = (int) DB::table('test_results')->count();
        if ($currentResults >= self::RESULT_TARGET) {
            return;
        }

        $need = self::RESULT_TARGET - $currentResults;
        $existing = DB::table('test_results')->pluck('test_appointment_id')->flip();
        $completed = DB::table('test_appointments')
            ->where('status', AppointmentStatus::Completed->value)
            ->orderBy('id')
            ->limit($need * 3)
            ->get(['id', 'application_id', 'test_type_id']);

        $rows = [];
        foreach ($completed as $appt) {
            if (isset($existing[(int) $appt->id])) {
                continue;
            }
            if (count($rows) >= $need) {
                break;
            }
            $recordedAt = $this->ts(-5);
            $rows[] = [
                'application_id' => (int) $appt->application_id,
                'test_appointment_id' => (int) $appt->id,
                'test_type_id' => (int) $appt->test_type_id,
                'result' => TestResultStatus::Passed->value,
                'attempt_number' => 1,
                'notes' => 'benchmark pad',
                'recorded_by' => $testerId,
                'recorded_at' => $recordedAt,
                'created_at' => $recordedAt,
                'updated_at' => $recordedAt,
            ];
        }
        if ($rows !== []) {
            $this->insertChunked('test_results', $rows);
        }
    }

    private function seedFinesAndFinePayments(int $paymentSeq): void
    {
        $licenseIds = DB::table('licenses')->orderBy('id')->limit(self::LICENSE_COUNT)->get(['id', 'citizen_id']);
        $fineStart = $this->nextId('fines');
        $fineRows = [];
        $paidFineIndexes = [];

        for ($i = 0; $i < self::FINE_TARGET; $i++) {
            $license = $licenseIds[$i % $licenseIds->count()];
            $created = $this->ts(-80 + ($i % 70));
            $mod = $i % 10;
            if ($mod < 6) {
                $status = FineStatus::Unpaid->value;
                $paidAt = null;
            } elseif ($mod < 9) {
                $status = FineStatus::Paid->value;
                $paidAt = $this->shift($created, 5);
                $paidFineIndexes[] = $i;
            } else {
                $status = FineStatus::Cancelled->value;
                $paidAt = null;
            }

            $amount = number_format(25 + ($i % 20) * 5, 2, '.', '');
            $fineRows[] = [
                'citizen_id' => (int) $license->citizen_id,
                'license_id' => (int) $license->id,
                'amount' => $amount,
                'reason' => sprintf('benchmark fine reason %d', ($i % 17) + 1),
                'status' => $status,
                'paid_at' => $paidAt,
                'created_at' => $created,
                'updated_at' => $paidAt ?? $created,
            ];

            if (count($fineRows) >= self::CHUNK) {
                $this->insertChunked('fines', $fineRows);
                $fineRows = [];
            }
        }

        if ($fineRows !== []) {
            $this->insertChunked('fines', $fineRows);
        }

        $paymentRows = [];
        foreach ($paidFineIndexes as $i) {
            $fineId = $fineStart + $i;
            $fine = DB::table('fines')->where('id', $fineId)->first(['amount', 'paid_at', 'citizen_id']);
            if ($fine === null) {
                continue;
            }

            $paymentRows[] = [
                'payment_number' => sprintf('PAY-BM-%08d', ++$paymentSeq),
                'user_id' => (int) $fine->citizen_id,
                'application_id' => null,
                'fine_id' => $fineId,
                'fee_id' => null,
                'payable_type' => null,
                'payable_id' => null,
                'amount' => (string) $fine->amount,
                'currency' => ApplicationFeeCatalog::CURRENCY,
                'status' => PaymentStatus::Completed->value,
                'provider' => 'mock',
                'provider_reference' => sprintf('mock_fine_bm_%08d', $paymentSeq),
                'paid_at' => $fine->paid_at,
                'metadata' => json_encode(['source' => 'benchmark_fine'], JSON_UNESCAPED_UNICODE),
                'failure_code' => null,
                'failure_message' => null,
                'failed_at' => null,
                'last_verified_at' => $fine->paid_at,
                'settled_obligation_key' => null,
                'active_obligation_key' => null,
                'created_at' => $fine->paid_at,
                'updated_at' => $fine->paid_at,
            ];

            if (count($paymentRows) >= self::CHUNK) {
                $this->insertChunked('payments', $paymentRows);
                $paymentRows = [];
            }
        }

        if ($paymentRows !== []) {
            $this->insertChunked('payments', $paymentRows);
        }
    }

    private function padLicenseHistories(int $existing): void
    {
        if ($existing >= self::LICENSE_HISTORY_TARGET) {
            return;
        }

        $need = self::LICENSE_HISTORY_TARGET - $existing;
        $licenses = DB::table('licenses')->orderBy('id')->limit(min(self::LICENSE_COUNT, $need))->pluck('id');
        $rows = [];
        for ($i = 0; $i < $need; $i++) {
            $licenseId = (int) $licenses[$i % $licenses->count()];
            $at = $this->ts(-40 + ($i % 35));
            $rows[] = [
                'license_id' => $licenseId,
                'from_status' => LicenseStatus::Active->value,
                'to_status' => LicenseStatus::Active->value,
                'action' => 'license.printed',
                'reason' => 'benchmark history pad',
                'performed_by' => $this->employeeIds[$i % count($this->employeeIds)],
                'source' => 'benchmark_seeder',
                'metadata' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ];
            if (count($rows) >= self::CHUNK) {
                $this->insertChunked('license_status_histories', $rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->insertChunked('license_status_histories', $rows);
        }
    }

    private function padApplicationHistories(int $appStartId): void
    {
        $current = (int) DB::table('application_status_histories')->count();
        if ($current >= self::APP_HISTORY_TARGET) {
            return;
        }

        $need = self::APP_HISTORY_TARGET - $current;
        $rows = [];
        for ($i = 0; $i < $need; $i++) {
            $appId = $appStartId + ($i % self::APPLICATION_COUNT);
            $at = $this->ts(-20 + ($i % 15));
            $rows[] = [
                'application_id' => $appId,
                'old_status' => ApplicationStatus::DocumentsUnderReview->value,
                'new_status' => ApplicationStatus::DocumentsUnderReview->value,
                'changed_by' => $this->employeeIds[$i % count($this->employeeIds)],
                'reason' => null,
                'notes' => 'benchmark history pad',
                'created_at' => $at,
                'updated_at' => $at,
            ];
            if (count($rows) >= self::CHUNK) {
                $this->insertChunked('application_status_histories', $rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->insertChunked('application_status_histories', $rows);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $appMeta
     */
    private function padDocumentsIfNeeded(int $appStartId, array $appMeta): void
    {
        $current = (int) DB::table('application_documents')->count();
        if ($current >= self::DOCUMENT_TARGET) {
            return;
        }

        $need = self::DOCUMENT_TARGET - $current;
        $reqIds = DB::table('required_documents')->orderBy('id')->pluck('id');
        if ($reqIds->isEmpty()) {
            return;
        }

        $rows = [];
        for ($i = 0; $i < $need; $i++) {
            $appIndex = $i % self::APPLICATION_COUNT;
            $appId = $appStartId + $appIndex;
            $reqId = (int) $reqIds[$i % $reqIds->count()];
            $submitted = (string) ($appMeta[$appIndex]['submitted_at'] ?? $this->ts(-10));
            $rows[] = [
                'application_id' => $appId,
                'required_document_id' => $reqId,
                'file_path' => sprintf('benchmark/documents/pad/%d/%d.pdf', $appId, $i),
                'original_name' => sprintf('pad-doc-%d.pdf', $i),
                'mime_type' => 'application/pdf',
                'size' => 40_000,
                'status' => DocumentStatus::PendingReview->value,
                'rejection_reason' => null,
                'rejection_reason_code' => null,
                'rejection_details' => null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'created_at' => $submitted,
                'updated_at' => $submitted,
            ];
            if (count($rows) >= self::CHUNK) {
                $this->insertChunked('application_documents', $rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->insertChunked('application_documents', $rows);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $appMeta
     */
    private function padPaymentsIfNeeded(int $appStartId, array $appMeta, int $paymentSeq): void
    {
        $current = (int) DB::table('payments')->count();
        if ($current >= self::PAYMENT_TARGET) {
            return;
        }

        $need = self::PAYMENT_TARGET - $current;
        $rows = [];
        for ($i = 0; $i < $need; $i++) {
            $appIndex = $i % self::APPLICATION_COUNT;
            $meta = $appMeta[$appIndex];
            $appId = $appStartId + $appIndex;
            $fee = $this->resolveFee((string) $meta['service'], (int) $meta['license_type_id']);
            // Failed attempts do not use obligation unique keys
            $rows[] = [
                'payment_number' => sprintf('PAY-BM-%08d', ++$paymentSeq),
                'user_id' => (int) $meta['citizen_id'],
                'application_id' => $appId,
                'fine_id' => null,
                'fee_id' => $fee['id'],
                'payable_type' => null,
                'payable_id' => null,
                'amount' => $fee['amount'],
                'currency' => $fee['currency'] ?: ApplicationFeeCatalog::CURRENCY,
                'status' => PaymentStatus::Failed->value,
                'provider' => 'mock',
                'provider_reference' => sprintf('mock_pad_bm_%08d', $paymentSeq),
                'paid_at' => null,
                'metadata' => json_encode(['source' => 'benchmark_pad'], JSON_UNESCAPED_UNICODE),
                'failure_code' => 'card_declined',
                'failure_message' => 'benchmark pad failed payment',
                'failed_at' => $this->ts(-3),
                'last_verified_at' => null,
                'settled_obligation_key' => null,
                'active_obligation_key' => null,
                'created_at' => $this->ts(-4),
                'updated_at' => $this->ts(-3),
            ];
            if (count($rows) >= self::CHUNK) {
                $this->insertChunked('payments', $rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->insertChunked('payments', $rows);
        }
    }

    private function seedNotifications(): void
    {
        $types = [
            'application.created',
            'application.payment_pending',
            'application.appointment_pending',
            'payment.completed',
            'license.issued',
            'fine.created',
            'document.approved',
            'test_result.passed',
        ];

        $rows = [];
        for ($i = 0; $i < self::NOTIFICATION_COUNT; $i++) {
            $citizenId = $this->citizenIds[$i % self::CITIZEN_COUNT];
            $type = $types[$i % count($types)];
            $created = $this->ts(-60 + ($i % 55));
            $rows[] = [
                'user_id' => $citizenId,
                'title' => sprintf('Benchmark notification %d', $i + 1),
                'body' => sprintf('Deterministic benchmark body for event %s (#%d).', $type, $i + 1),
                'type' => $type,
                'read_at' => $i % 3 === 0 ? $this->shift($created, 1) : null,
                'data' => json_encode(['benchmark' => true, 'seq' => $i + 1], JSON_UNESCAPED_UNICODE),
                'event_key' => sprintf('benchmark:notification:%d', $i + 1),
                'created_at' => $created,
                'updated_at' => $created,
            ];
            if (count($rows) >= self::CHUNK) {
                $this->insertChunked('notifications', $rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->insertChunked('notifications', $rows);
        }
    }

    private function seedAuditLogs(): void
    {
        $actions = [
            'application.created',
            'application.status_changed',
            'document.approved',
            'document.rejected',
            'payment.completed',
            'license.issued',
            'license.blocked',
            'fine.created',
            'fine.updated',
            'test_result.recorded',
        ];
        $entities = ['application', 'document', 'payment', 'license', 'fine', 'user'];

        $rows = [];
        for ($i = 0; $i < self::AUDIT_LOG_COUNT; $i++) {
            $actor = $i % 5 === 0
                ? $this->employeeIds[$i % count($this->employeeIds)]
                : $this->citizenIds[$i % self::CITIZEN_COUNT];
            $created = $this->ts(-70 + ($i % 65));
            $rows[] = [
                'user_id' => $actor,
                'action' => $actions[$i % count($actions)],
                'entity_type' => $entities[$i % count($entities)],
                'entity_id' => ($i % 5000) + 1,
                'old_values' => $i % 4 === 0 ? json_encode(['status' => 'old'], JSON_UNESCAPED_UNICODE) : null,
                'new_values' => json_encode(['status' => 'new', 'seq' => $i + 1], JSON_UNESCAPED_UNICODE),
                'ip_address' => sprintf('10.%d.%d.%d', ($i >> 16) & 255, ($i >> 8) & 255, $i & 255),
                'user_agent' => 'SYRTAK-BenchmarkSeeder/1.0',
                'created_at' => $created,
                'updated_at' => $created,
            ];
            if (count($rows) >= self::CHUNK) {
                $this->insertChunked('audit_logs', $rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->insertChunked('audit_logs', $rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationRow(
        int $index,
        int $citizenId,
        int $licenseTypeId,
        int $serviceTypeId,
        ?int $relatedLicenseId,
        string $status,
        ?int $currentTestTypeId,
        ?string $rejectionReason,
        ?string $submittedAt,
        ?string $approvedAt,
        ?string $issuedAt,
    ): array {
        $created = $submittedAt ?? $this->ts(-30);

        return [
            'application_number' => sprintf('APP-BM-%07d', $index + 1),
            'citizen_id' => $citizenId,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $serviceTypeId,
            'related_license_id' => $relatedLicenseId,
            'status' => $status,
            'current_test_type_id' => $currentTestTypeId,
            'rejection_reason' => $rejectionReason,
            'submitted_at' => $submittedAt,
            'approved_at' => $approvedAt,
            'issued_at' => $issuedAt,
            'created_at' => $created,
            'updated_at' => $issuedAt ?? $approvedAt ?? $submittedAt ?? $created,
        ];
    }

    /**
     * @return list<string>
     */
    private function statusPath(string $final): array
    {
        return match ($final) {
            ApplicationStatus::Draft->value => [ApplicationStatus::Draft->value],
            ApplicationStatus::DocumentsUnderReview->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
            ],
            ApplicationStatus::DocumentsRejected->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::DocumentsRejected->value,
            ],
            ApplicationStatus::PaymentPending->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::PaymentPending->value,
            ],
            ApplicationStatus::PaymentCompleted->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::PaymentPending->value,
                ApplicationStatus::PaymentCompleted->value,
            ],
            ApplicationStatus::AppointmentPending->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::PaymentPending->value,
                ApplicationStatus::PaymentCompleted->value,
                ApplicationStatus::AppointmentPending->value,
            ],
            ApplicationStatus::InTesting->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::PaymentPending->value,
                ApplicationStatus::PaymentCompleted->value,
                ApplicationStatus::AppointmentPending->value,
                ApplicationStatus::InTesting->value,
            ],
            ApplicationStatus::WaitingRetest->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::PaymentPending->value,
                ApplicationStatus::InTesting->value,
                ApplicationStatus::WaitingRetest->value,
            ],
            ApplicationStatus::Approved->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::PaymentPending->value,
                ApplicationStatus::InTesting->value,
                ApplicationStatus::Approved->value,
            ],
            ApplicationStatus::LicenseIssued->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::PaymentPending->value,
                ApplicationStatus::InTesting->value,
                ApplicationStatus::Approved->value,
                ApplicationStatus::LicenseIssued->value,
            ],
            ApplicationStatus::Rejected->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::Rejected->value,
            ],
            ApplicationStatus::Cancelled->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::Cancelled->value,
            ],
            ApplicationStatus::AdministrativeReview->value => [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsUnderReview->value,
                ApplicationStatus::PaymentPending->value,
                ApplicationStatus::AdministrativeReview->value,
            ],
            default => [$final],
        };
    }

    private function documentStatusForApplication(string $appStatus, int $seq): string
    {
        return match ($appStatus) {
            ApplicationStatus::Draft->value => DocumentStatus::PendingReview->value,
            ApplicationStatus::DocumentsUnderReview->value => DocumentStatus::PendingReview->value,
            ApplicationStatus::DocumentsRejected->value => $seq % 2 === 0
                ? DocumentStatus::Rejected->value
                : DocumentStatus::Approved->value,
            ApplicationStatus::Rejected->value => DocumentStatus::Rejected->value,
            ApplicationStatus::Cancelled->value => DocumentStatus::PendingReview->value,
            default => DocumentStatus::Approved->value,
        };
    }

    private function applicationNeedsPaymentRow(string $status): bool
    {
        return in_array($status, [
            ApplicationStatus::PaymentPending->value,
            ApplicationStatus::PaymentCompleted->value,
            ApplicationStatus::AppointmentPending->value,
            ApplicationStatus::InTesting->value,
            ApplicationStatus::WaitingRetest->value,
            ApplicationStatus::Approved->value,
            ApplicationStatus::LicenseIssued->value,
            ApplicationStatus::AdministrativeReview->value,
        ], true);
    }

    private function paymentStatusForApplication(string $appStatus, int $seq): string
    {
        if ($appStatus === ApplicationStatus::PaymentPending->value) {
            return $seq % 5 === 0
                ? PaymentStatus::UnderVerification->value
                : PaymentStatus::Pending->value;
        }

        if (in_array($appStatus, [
            ApplicationStatus::PaymentCompleted->value,
            ApplicationStatus::AppointmentPending->value,
            ApplicationStatus::InTesting->value,
            ApplicationStatus::WaitingRetest->value,
            ApplicationStatus::Approved->value,
            ApplicationStatus::LicenseIssued->value,
            ApplicationStatus::AdministrativeReview->value,
        ], true)) {
            return PaymentStatus::Completed->value;
        }

        return PaymentStatus::Failed->value;
    }

    private function applicationNeedsAppointments(string $status): bool
    {
        return in_array($status, [
            ApplicationStatus::AppointmentPending->value,
            ApplicationStatus::InTesting->value,
            ApplicationStatus::WaitingRetest->value,
            ApplicationStatus::Approved->value,
            ApplicationStatus::LicenseIssued->value,
        ], true);
    }

    /**
     * @param  list<int>  $orderedTests
     * @return list<int>
     */
    private function testsForStatus(string $status, array $orderedTests): array
    {
        return match ($status) {
            ApplicationStatus::AppointmentPending->value => [$orderedTests[0]],
            ApplicationStatus::InTesting->value => [$orderedTests[0], $orderedTests[1]],
            ApplicationStatus::WaitingRetest->value => [$orderedTests[0], $orderedTests[1]],
            ApplicationStatus::Approved->value,
            ApplicationStatus::LicenseIssued->value => $orderedTests,
            default => [$orderedTests[0]],
        };
    }

    private function appointmentStatusFor(string $appStatus, int $testIndex, int $total): string
    {
        if ($appStatus === ApplicationStatus::AppointmentPending->value) {
            return AppointmentStatus::Booked->value;
        }

        if ($appStatus === ApplicationStatus::InTesting->value) {
            return $testIndex < $total - 1
                ? AppointmentStatus::Completed->value
                : AppointmentStatus::Booked->value;
        }

        if ($appStatus === ApplicationStatus::WaitingRetest->value) {
            return AppointmentStatus::Completed->value;
        }

        return AppointmentStatus::Completed->value;
    }

    /**
     * @return array{id:int, amount:string, currency:string}
     */
    private function resolveFee(string $serviceCode, int $licenseTypeId): array
    {
        $feeCode = match ($serviceCode) {
            'renew_license' => 'renewal_fee',
            'lost_replacement' => 'lost_replacement_fee',
            'damaged_replacement' => 'damaged_replacement_fee',
            'license_unblock' => 'unblock_fee',
            default => 'application_fee',
        };

        if ($feeCode === 'application_fee') {
            $key = 'application_fee:'.$licenseTypeId;
            if (isset($this->feesByCode[$key])) {
                return $this->feesByCode[$key];
            }
        }

        if (! isset($this->feesByCode[$feeCode])) {
            throw new RuntimeException("Missing fee for code [{$feeCode}].");
        }

        return $this->feesByCode[$feeCode];
    }

    private function pickSlot(int $seq): int
    {
        if ($this->slotIds === []) {
            throw new RuntimeException('No appointment slots available.');
        }

        return $this->slotIds[$seq % count($this->slotIds)];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunked(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function nextId(string $table): int
    {
        $max = DB::table($table)->max('id');

        return $max ? ((int) $max + 1) : 1;
    }

    private function ts(int $daysOffset, int $hoursOffset = 0): string
    {
        return $this->now->addDays($daysOffset)->addHours($hoursOffset)->format('Y-m-d H:i:s');
    }

    private function shift(string $timestamp, int $days): string
    {
        return CarbonImmutable::parse($timestamp)->addDays($days)->format('Y-m-d H:i:s');
    }

    private function printSummary(float $durationSeconds): void
    {
        $tables = [
            'users',
            'license_applications',
            'application_documents',
            'payments',
            'test_appointments',
            'test_results',
            'licenses',
            'fines',
            'notifications',
            'audit_logs',
            'application_status_histories',
            'license_status_histories',
            'appointment_slots',
        ];

        $this->command?->newLine();
        $this->command?->info('=== SYRTAK Benchmark Dataset ===');
        $this->command?->info(sprintf('Seed duration: %.2f seconds', $durationSeconds));
        $this->command?->info(sprintf('Database: %s', DB::connection()->getDatabaseName()));
        $this->command?->newLine();
        $this->command?->info('Row counts:');
        foreach ($tables as $table) {
            $count = DB::table($table)->count();
            $this->command?->line(sprintf('  %-32s %d', $table, $count));
        }

        $citizenCount = DB::table('users')->where('user_type', UserType::Citizen->value)->count();
        $employeeCount = DB::table('users')->whereIn('user_type', [UserType::Admin->value, UserType::Employee->value])->count();
        $this->command?->line(sprintf('  %-32s %d', 'users (citizens)', $citizenCount));
        $this->command?->line(sprintf('  %-32s %d', 'users (employees/admins)', $employeeCount));

        $this->command?->newLine();
        $this->command?->info('Benchmark accounts (authenticate normally; no API tokens seeded):');
        $this->command?->line('  Dashboard: '.self::ADMIN_EMAIL.' / '.self::ACCOUNT_PASSWORD);
        $this->command?->line('  Citizen:   '.self::CITIZEN_EMAIL.' / '.self::ACCOUNT_PASSWORD);
        $this->command?->newLine();
    }
}

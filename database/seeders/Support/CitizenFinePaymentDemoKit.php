<?php

namespace Database\Seeders\Support;

use App\Enums\ApplicationStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\PaymentFailureCode;
use App\Enums\PaymentStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserType;
use App\Models\Fee;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseType;
use App\Models\Payment;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\User;
use App\Modules\Payments\Support\ApplicationFeeResolver;
use Carbon\Carbon;
use Database\Seeders\FeesSeeder;
use Database\Seeders\LicenseTypesSeeder;
use Database\Seeders\PermissionsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\ServiceTypesSeeder;
use Database\Seeders\TestTypesSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Deterministic local/testing fixtures for Citizen Fine Payment + My Payments demos.
 * Does not call Stripe, send mail/push, or emit lifecycle side effects.
 */
final class CitizenFinePaymentDemoKit
{
    public const PASSWORD = 'password';

    public const HAPPY_EMAIL = 'demo.fine.happy@syrtak.local';

    public const MIXED_EMAIL = 'demo.fine.payments@syrtak.local';

    public const BLOCKED_EMAIL = 'demo.fine.blocked@syrtak.local';

    public const OTHER_EMAIL = 'demo.fine.other@syrtak.local';

    public const PAY_PREFIX = 'PAY-CFP-';

    public const APP_PREFIX = 'APP-CFP-';

    public const LIC_PREFIX = 'LIC-CFP-';

    /** Fake Stripe Checkout Session ids for return-page QA (local DB only). */
    public const SESSION_SUCCESS = 'cs_test_seed_return_success';

    public const SESSION_PENDING = 'cs_test_seed_return_pending';

    public const SESSION_VERIFYING = 'cs_test_seed_return_verifying';

    public const SESSION_FAILED = 'cs_test_seed_return_failed';

    /** @var array<string, mixed> */
    private array $summary = [];

    public function __construct(
        private readonly Seeder $seeder,
        private readonly ?Command $command = null,
    ) {}

    public function guardEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'Citizen Fine Payment demo seeder may only run in the local or testing environment.'
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
            FeesSeeder::class,
        ]);
    }

    public function seedAll(): void
    {
        $happy = $this->upsertCitizen(self::HAPPY_EMAIL, 'أحمد فادي الخطيب', '0997100001', '01071000001');
        $mixed = $this->upsertCitizen(self::MIXED_EMAIL, 'ليلى سامي الحسن', '0997100002', '01071000002');
        $blocked = $this->upsertCitizen(self::BLOCKED_EMAIL, 'خالد عمر العلي', '0997100003', '01071000003');
        $other = $this->upsertCitizen(self::OTHER_EMAIL, 'سارة وائل ناصر', '0997100004', '01071000004');

        $this->purgeDemoFinancials([$happy, $mixed, $blocked, $other]);

        $this->seedHappyPathCitizen($happy);
        $this->seedMixedPaymentsCitizen($mixed);
        $this->seedBlockedCitizen($blocked);
        $this->seedOwnershipCitizen($other);

        $this->summary['citizens'] = [
            'happy' => self::HAPPY_EMAIL,
            'mixed' => self::MIXED_EMAIL,
            'blocked' => self::BLOCKED_EMAIL,
            'other' => self::OTHER_EMAIL,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->summary;
    }

    public function printSummary(): void
    {
        $this->info('────────────────────────────────────────');
        $this->info('Citizen Fine Payment Demo Seeded');
        $this->info('Password (local/testing only): '.self::PASSWORD);
        $this->info('Happy-path citizen: '.self::HAPPY_EMAIL);
        $this->info('Mixed My Payments:  '.self::MIXED_EMAIL);
        $this->info('Blocked license:    '.self::BLOCKED_EMAIL);
        $this->info('Ownership other:    '.self::OTHER_EMAIL);
        foreach ([
            'FINE-01 unpaid id' => $this->summary['FINE-01'] ?? null,
            'FINE-03 paid id' => $this->summary['FINE-03'] ?? null,
            'FINE-06 cancelled id' => $this->summary['FINE-06'] ?? null,
            'Pending payment' => $this->summary['PAY-PENDING'] ?? null,
            'Completed payment' => $this->summary['PAY-COMPLETED'] ?? null,
            'Failed payment' => $this->summary['PAY-FAILED'] ?? null,
            'Under-verification payment' => $this->summary['PAY-UV'] ?? null,
            'Return success session' => self::SESSION_SUCCESS,
            'Return pending session' => self::SESSION_PENDING,
            'Return verifying session' => self::SESSION_VERIFYING,
            'Return failed session' => self::SESSION_FAILED,
        ] as $label => $value) {
            if ($value !== null && $value !== '') {
                $this->info($label.': '.$value);
            }
        }
        $this->info('Docs: docs/CITIZEN_FINE_PAYMENT_DEMO_SEEDER.md');
        $this->info('────────────────────────────────────────');
    }

    public function info(string $message): void
    {
        $this->command?->info($message);
    }

    private function seedHappyPathCitizen(User $citizen): void
    {
        $license = $this->ensureLicense(
            $citizen,
            self::LIC_PREFIX.'HAPPY-ACTIVE',
            self::APP_PREFIX.'HAPPY-ISSUE',
            LicenseStatus::Active,
            'new_license'
        );

        // FINE-01 — unpaid, ready to Pay Fine (no payment row).
        $fine01 = $this->upsertFine(
            $citizen,
            $license,
            '[CFP-FINE-01] تجاوز السرعة المحددة على الطريق العام.',
            FineStatus::Unpaid,
            25.00
        );
        $this->summary['FINE-01'] = $fine01->id;

        // FINE-06 — cancelled, not payable.
        $fine06 = $this->upsertFine(
            $citizen,
            $license,
            '[CFP-FINE-06] مخالفة ملغاة — خطأ في التسجيل.',
            FineStatus::Cancelled,
            15.00
        );
        $this->summary['FINE-06'] = $fine06->id;

        // FINE-03 — paid + completed payment (historical integrity: payment 25, fine amount later 30).
        $fine03 = $this->upsertFine(
            $citizen,
            $license,
            '[CFP-FINE-03] الوقوف في مكان ممنوع.',
            FineStatus::Paid,
            25.00,
            now()->subDays(2)
        );
        $payCompleted = $this->upsertFinePayment(
            paymentNumber: self::PAY_PREFIX.'FINE-03-COMPLETED',
            citizen: $citizen,
            fine: $fine03,
            status: PaymentStatus::Completed,
            amount: '25.00',
            provider: 'stripe',
            providerReference: self::SESSION_SUCCESS,
            when: now()->subDays(2),
        );
        $fine03->forceFill(['amount' => 30.00])->saveQuietly();
        $this->summary['FINE-03'] = $fine03->id;
        $this->summary['PAY-COMPLETED'] = $payCompleted->id;
    }

    private function seedMixedPaymentsCitizen(User $citizen): void
    {
        $license = $this->ensureLicense(
            $citizen,
            self::LIC_PREFIX.'MIXED-ACTIVE',
            self::APP_PREFIX.'MIXED-ISSUE',
            LicenseStatus::Active,
            'new_license'
        );

        // FINE-02 pending (return page processing).
        $fine02 = $this->upsertFine(
            $citizen,
            $license,
            '[CFP-FINE-02] قطع الإشارة الضوئية الحمراء.',
            FineStatus::Unpaid,
            20.00
        );
        $payPending = $this->upsertFinePayment(
            paymentNumber: self::PAY_PREFIX.'FINE-02-PENDING',
            citizen: $citizen,
            fine: $fine02,
            status: PaymentStatus::Pending,
            amount: '20.00',
            provider: 'stripe',
            providerReference: self::SESSION_PENDING,
            when: now()->subHours(3),
        );
        $this->summary['FINE-02'] = $fine02->id;
        $this->summary['PAY-PENDING'] = $payPending->id;

        // FINE-04 failed attempt (retry still possible).
        $fine04 = $this->upsertFine(
            $citizen,
            $license,
            '[CFP-FINE-04] استخدام الهاتف أثناء القيادة.',
            FineStatus::Unpaid,
            18.00
        );
        $payFailed = $this->upsertFinePayment(
            paymentNumber: self::PAY_PREFIX.'FINE-04-FAILED',
            citizen: $citizen,
            fine: $fine04,
            status: PaymentStatus::Failed,
            amount: '18.00',
            provider: 'stripe',
            providerReference: self::SESSION_FAILED,
            when: now()->subDay(),
            extra: [
                'failure_code' => PaymentFailureCode::AsyncPaymentFailed->value,
                'failure_message' => 'Demo: payment failed at provider (seed fixture).',
                'failed_at' => now()->subDay(),
            ],
        );
        $this->summary['FINE-04'] = $fine04->id;
        $this->summary['PAY-FAILED'] = $payFailed->id;

        // FINE-05 under verification.
        $fine05 = $this->upsertFine(
            $citizen,
            $license,
            '[CFP-FINE-05] عدم الالتزام بمسارب الطريق.',
            FineStatus::Unpaid,
            22.00
        );
        $payUv = $this->upsertFinePayment(
            paymentNumber: self::PAY_PREFIX.'FINE-05-UV',
            citizen: $citizen,
            fine: $fine05,
            status: PaymentStatus::UnderVerification,
            amount: '22.00',
            provider: 'stripe',
            providerReference: self::SESSION_VERIFYING,
            when: now()->subHours(1),
            extra: [
                'last_verified_at' => now()->subMinutes(30),
            ],
        );
        $this->summary['FINE-05'] = $fine05->id;
        $this->summary['PAY-UV'] = $payUv->id;

        // Completed fine payment (mock) for history variety.
        $finePaidMixed = $this->upsertFine(
            $citizen,
            $license,
            '[CFP-FINE-MIX-PAID] عدم ربط حزام الأمان.',
            FineStatus::Paid,
            12.00,
            now()->subDays(5)
        );
        $this->upsertFinePayment(
            paymentNumber: self::PAY_PREFIX.'FINE-MIX-COMPLETED',
            citizen: $citizen,
            fine: $finePaidMixed,
            status: PaymentStatus::Completed,
            amount: '12.00',
            provider: 'mock',
            providerReference: null,
            when: now()->subDays(5),
        );

        // Application fee history across supported service types.
        $this->seedApplicationPayment(
            $citizen,
            self::APP_PREFIX.'NEW',
            'new_license',
            PaymentStatus::Completed,
            now()->subDays(10),
            needsRelatedLicense: false
        );
        $this->seedApplicationPayment(
            $citizen,
            self::APP_PREFIX.'RENEW',
            'renew_license',
            PaymentStatus::Completed,
            now()->subDays(8),
            needsRelatedLicense: true,
            relatedLicense: $license
        );
        $this->seedApplicationPayment(
            $citizen,
            self::APP_PREFIX.'LOST',
            'lost_replacement',
            PaymentStatus::Completed,
            now()->subDays(7),
            needsRelatedLicense: true,
            relatedLicense: $license
        );
        $this->seedApplicationPayment(
            $citizen,
            self::APP_PREFIX.'DAMAGED',
            'damaged_replacement',
            PaymentStatus::Completed,
            now()->subDays(6),
            needsRelatedLicense: true,
            relatedLicense: $license
        );
        $this->seedApplicationPayment(
            $citizen,
            self::APP_PREFIX.'UNBLOCK',
            'license_unblock',
            PaymentStatus::Completed,
            now()->subDays(4),
            needsRelatedLicense: true,
            relatedLicense: $license,
            relatedLicenseStatus: LicenseStatus::Blocked
        );

        // Test fee payment (vision) — uses real fee code.
        $this->seedTestFeePayment($citizen, $license);

        // Extra application payment statuses for My Payments filters.
        $this->seedApplicationPayment(
            $citizen,
            self::APP_PREFIX.'PENDING-APP',
            'new_license',
            PaymentStatus::Pending,
            now()->subHours(5),
            needsRelatedLicense: false,
            licenseTypeCode: 'public'
        );
        $this->seedApplicationPayment(
            $citizen,
            self::APP_PREFIX.'FAILED-APP',
            'renew_license',
            PaymentStatus::Failed,
            now()->subDays(1),
            needsRelatedLicense: true,
            relatedLicense: $license
        );
        $this->seedApplicationPayment(
            $citizen,
            self::APP_PREFIX.'UV-APP',
            'lost_replacement',
            PaymentStatus::UnderVerification,
            now()->subHours(2),
            needsRelatedLicense: true,
            relatedLicense: $license
        );
    }

    private function seedBlockedCitizen(User $citizen): void
    {
        // FINE-07 — blocked license + unpaid fine (blocks unblock eligibility).
        $blockedUnpaidLicense = $this->ensureLicense(
            $citizen,
            self::LIC_PREFIX.'BLOCKED-UNPAID',
            self::APP_PREFIX.'BLOCKED-UNPAID-ISSUE',
            LicenseStatus::Blocked,
            'new_license'
        );
        $fine07 = $this->upsertFine(
            $citizen,
            $blockedUnpaidLicense,
            '[CFP-FINE-07] مخالفة على رخصة محظورة — غرامة غير مدفوعة.',
            FineStatus::Unpaid,
            35.00
        );
        $this->summary['FINE-07'] = $fine07->id;
        $this->summary['LIC-BLOCKED-UNPAID'] = $blockedUnpaidLicense->id;

        // FINE-08 — blocked license + paid fine (blocker cleared; license stays blocked).
        $blockedPaidLicense = $this->ensureLicense(
            $citizen,
            self::LIC_PREFIX.'BLOCKED-PAID',
            self::APP_PREFIX.'BLOCKED-PAID-ISSUE',
            LicenseStatus::Blocked,
            'new_license',
            licenseTypeCode: 'public'
        );
        $fine08 = $this->upsertFine(
            $citizen,
            $blockedPaidLicense,
            '[CFP-FINE-08] مخالفة مسددة على رخصة لا تزال محظورة.',
            FineStatus::Paid,
            28.00,
            now()->subDays(3)
        );
        $this->upsertFinePayment(
            paymentNumber: self::PAY_PREFIX.'FINE-08-COMPLETED',
            citizen: $citizen,
            fine: $fine08,
            status: PaymentStatus::Completed,
            amount: '28.00',
            provider: 'mock',
            providerReference: null,
            when: now()->subDays(3),
        );
        $this->summary['FINE-08'] = $fine08->id;
        $this->summary['LIC-BLOCKED-PAID'] = $blockedPaidLicense->id;
    }

    private function seedOwnershipCitizen(User $citizen): void
    {
        $license = $this->ensureLicense(
            $citizen,
            self::LIC_PREFIX.'OTHER-ACTIVE',
            self::APP_PREFIX.'OTHER-ISSUE',
            LicenseStatus::Active,
            'new_license'
        );

        $fine = $this->upsertFine(
            $citizen,
            $license,
            '[CFP-FINE-OTHER] مخالفة ملكية مواطن آخر — للاختبار الأمني.',
            FineStatus::Unpaid,
            40.00
        );
        $payment = $this->upsertFinePayment(
            paymentNumber: self::PAY_PREFIX.'OTHER-PENDING',
            citizen: $citizen,
            fine: $fine,
            status: PaymentStatus::Pending,
            amount: '40.00',
            provider: 'mock',
            providerReference: null,
            when: now()->subMinutes(20),
        );

        $this->summary['FINE-OTHER'] = $fine->id;
        $this->summary['PAY-OTHER'] = $payment->id;
    }

    /**
     * @param  list<User>  $citizens
     */
    private function purgeDemoFinancials(array $citizens): void
    {
        $ids = array_map(static fn (User $u): int => (int) $u->id, $citizens);

        Payment::withTrashed()
            ->whereIn('user_id', $ids)
            ->where('payment_number', 'like', self::PAY_PREFIX.'%')
            ->forceDelete();

        Fine::withTrashed()
            ->whereIn('citizen_id', $ids)
            ->where('reason', 'like', '%[CFP-%')
            ->forceDelete();

        // Drop demo applications (licenses cascade relations carefully).
        $apps = LicenseApplication::query()
            ->whereIn('citizen_id', $ids)
            ->where('application_number', 'like', self::APP_PREFIX.'%')
            ->get();

        foreach ($apps as $app) {
            Payment::withTrashed()
                ->where('application_id', $app->id)
                ->where('payment_number', 'like', self::PAY_PREFIX.'%')
                ->forceDelete();
        }

        License::query()
            ->whereIn('citizen_id', $ids)
            ->where('license_number', 'like', self::LIC_PREFIX.'%')
            ->forceDelete();

        LicenseApplication::query()
            ->whereIn('citizen_id', $ids)
            ->where('application_number', 'like', self::APP_PREFIX.'%')
            ->forceDelete();
    }

    private function upsertCitizen(string $email, string $name, string $phone, string $nationalId): User
    {
        $role = Role::query()->where('name', 'citizen')->firstOrFail();

        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => $phone,
                'national_id' => $nationalId,
                'password' => Hash::make(self::PASSWORD),
                'role_id' => $role->id,
                'user_type' => UserType::Citizen,
                'birth_date' => '1992-06-15',
                'governorate' => 'دمشق',
                'address' => 'دمشق — المزة — شارع الجلاء — بناء تجريبي لمدفوعات الغرامات',
                'language' => 'ar',
                'theme' => 'system',
                'profile_completed' => true,
                'profile_status' => ProfileStatus::Approved,
                'profile_submitted_at' => now()->subDays(45),
                'profile_reviewed_at' => now()->subDays(44),
                'profile_rejection_reason' => null,
                'is_active' => true,
                'email_verified_at' => now()->subDays(45),
                'phone_verified_at' => now()->subDays(45),
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ]
        );
    }

    private function ensureLicense(
        User $citizen,
        string $licenseNumber,
        string $applicationNumber,
        LicenseStatus $status,
        string $serviceCode,
        string $licenseTypeCode = 'private',
    ): License {
        $licenseType = LicenseType::query()->where('code', $licenseTypeCode)->firstOrFail();
        $serviceType = ServiceType::query()->where('code', $serviceCode)->firstOrFail();
        $issuedAt = now()->subYears(2);

        $application = LicenseApplication::query()->updateOrCreate(
            ['application_number' => $applicationNumber],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'related_license_id' => null,
                'status' => ApplicationStatus::LicenseIssued,
                'current_test_type_id' => null,
                'rejection_reason' => null,
                'submitted_at' => $issuedAt,
                'approved_at' => $issuedAt,
                'issued_at' => $issuedAt,
            ]
        );

        return License::query()->updateOrCreate(
            ['license_number' => $licenseNumber],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'application_id' => $application->id,
                'status' => $status,
                'issue_date' => $issuedAt->toDateString(),
                'expiry_date' => $issuedAt->copy()->addYears(10)->toDateString(),
            ]
        );
    }

    private function upsertFine(
        User $citizen,
        ?License $license,
        string $reason,
        FineStatus $status,
        float $amount,
        ?Carbon $paidAt = null,
    ): Fine {
        return Fine::withTrashed()->updateOrCreate(
            [
                'citizen_id' => $citizen->id,
                'reason' => $reason,
            ],
            [
                'license_id' => $license?->id,
                'amount' => $amount,
                'currency' => strtoupper((string) config('payment.fine_currency', 'USD')),
                'status' => $status,
                'paid_at' => $status === FineStatus::Paid ? ($paidAt ?? now()->subDays(2)) : null,
                'deleted_at' => null,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function upsertFinePayment(
        string $paymentNumber,
        User $citizen,
        Fine $fine,
        PaymentStatus $status,
        string $amount,
        string $provider,
        ?string $providerReference,
        Carbon $when,
        array $extra = [],
    ): Payment {
        $obligation = Payment::fineObligationKey((int) $fine->id);

        $active = in_array($status, [PaymentStatus::Pending, PaymentStatus::UnderVerification], true)
            ? $obligation
            : null;
        $settled = $status === PaymentStatus::Completed ? $obligation : null;

        return Payment::withTrashed()->updateOrCreate(
            ['payment_number' => $paymentNumber],
            array_merge([
                'user_id' => $citizen->id,
                'application_id' => null,
                'fine_id' => $fine->id,
                'fee_id' => null,
                'payable_type' => Fine::class,
                'payable_id' => $fine->id,
                'amount' => $amount,
                'currency' => 'USD',
                'status' => $status,
                'provider' => $provider,
                'provider_reference' => $providerReference,
                'paid_at' => $status === PaymentStatus::Completed ? $when : null,
                'failed_at' => $status === PaymentStatus::Failed ? ($extra['failed_at'] ?? $when) : null,
                'failure_code' => $extra['failure_code'] ?? null,
                'failure_message' => $extra['failure_message'] ?? null,
                'last_verified_at' => $extra['last_verified_at'] ?? null,
                'active_obligation_key' => $active,
                'settled_obligation_key' => $settled,
                'metadata' => [
                    'source' => 'citizen_fine_payment_demo_seeder',
                    'demo' => true,
                ],
                'deleted_at' => null,
                'created_at' => $when,
                'updated_at' => $when,
            ], array_diff_key($extra, array_flip(['failure_code', 'failure_message', 'failed_at', 'last_verified_at'])))
        );
    }

    private function seedApplicationPayment(
        User $citizen,
        string $applicationNumber,
        string $serviceCode,
        PaymentStatus $status,
        Carbon $when,
        bool $needsRelatedLicense,
        ?License $relatedLicense = null,
        ?LicenseStatus $relatedLicenseStatus = null,
        string $licenseTypeCode = 'private',
    ): Payment {
        $licenseType = LicenseType::query()->where('code', $licenseTypeCode)->firstOrFail();
        $serviceType = ServiceType::query()->where('code', $serviceCode)->firstOrFail();

        $relatedId = null;
        if ($needsRelatedLicense) {
            if ($relatedLicense === null) {
                throw new RuntimeException('Related license required for '.$serviceCode);
            }
            if ($relatedLicenseStatus !== null && $relatedLicense->status !== $relatedLicenseStatus) {
                // For unblock demo payment history we keep application pointing at blocked license
                // without mutating the shared active license used by other scenarios.
                $relatedId = $relatedLicense->id;
            } else {
                $relatedId = $relatedLicense->id;
            }
        }

        $appStatus = $status === PaymentStatus::Completed
            ? ApplicationStatus::Approved
            : ApplicationStatus::PaymentPending;

        $application = LicenseApplication::query()->updateOrCreate(
            ['application_number' => $applicationNumber],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'related_license_id' => $relatedId,
                'status' => $appStatus,
                'current_test_type_id' => null,
                'rejection_reason' => null,
                'submitted_at' => $when->copy()->subDay(),
                'approved_at' => $status === PaymentStatus::Completed ? $when : null,
                'issued_at' => null,
            ]
        );

        $fee = app(ApplicationFeeResolver::class)->resolve($application);
        $obligation = Payment::obligationKey((int) $application->id, (int) $fee->id);
        $paymentNumber = self::PAY_PREFIX.'APP-'.$applicationNumber;

        $active = in_array($status, [PaymentStatus::Pending, PaymentStatus::UnderVerification], true)
            ? $obligation
            : null;
        $settled = $status === PaymentStatus::Completed ? $obligation : null;

        return Payment::withTrashed()->updateOrCreate(
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
                'status' => $status,
                'provider' => $status === PaymentStatus::Completed ? 'mock' : 'stripe',
                'provider_reference' => $status === PaymentStatus::Completed
                    ? null
                    : 'cs_test_seed_app_'.strtolower(str_replace(['APP-CFP-', '-'], ['', '_'], $applicationNumber)),
                'paid_at' => $status === PaymentStatus::Completed ? $when : null,
                'failed_at' => $status === PaymentStatus::Failed ? $when : null,
                'failure_code' => $status === PaymentStatus::Failed
                    ? PaymentFailureCode::SessionExpired->value
                    : null,
                'failure_message' => $status === PaymentStatus::Failed
                    ? 'Demo: checkout session expired (seed fixture).'
                    : null,
                'last_verified_at' => $status === PaymentStatus::UnderVerification ? $when : null,
                'active_obligation_key' => $active,
                'settled_obligation_key' => $settled,
                'metadata' => [
                    'source' => 'citizen_fine_payment_demo_seeder',
                    'demo' => true,
                    'service_code' => $serviceCode,
                ],
                'deleted_at' => null,
                'created_at' => $when,
                'updated_at' => $when,
            ]
        );
    }

    private function seedTestFeePayment(User $citizen, License $license): void
    {
        $licenseType = LicenseType::query()->where('code', 'private')->firstOrFail();
        $serviceType = ServiceType::query()->where('code', 'new_license')->firstOrFail();
        $when = now()->subDays(3);

        $application = LicenseApplication::query()->updateOrCreate(
            ['application_number' => self::APP_PREFIX.'VISION-FEE'],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'related_license_id' => null,
                'status' => ApplicationStatus::Approved,
                'submitted_at' => $when->copy()->subDay(),
                'approved_at' => $when,
                'issued_at' => null,
            ]
        );

        $fee = Fee::query()->where('code', 'vision_test_fee')->where('is_active', true)->firstOrFail();
        $obligation = Payment::obligationKey((int) $application->id, (int) $fee->id);

        Payment::withTrashed()->updateOrCreate(
            ['payment_number' => self::PAY_PREFIX.'APP-VISION-FEE'],
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
                'provider_reference' => null,
                'paid_at' => $when,
                'active_obligation_key' => null,
                'settled_obligation_key' => $obligation,
                'metadata' => [
                    'source' => 'citizen_fine_payment_demo_seeder',
                    'demo' => true,
                    'fee_code' => 'vision_test_fee',
                ],
                'deleted_at' => null,
                'created_at' => $when,
                'updated_at' => $when,
            ]
        );
    }
}

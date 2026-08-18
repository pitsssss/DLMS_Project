<?php

namespace Database\Seeders\Support;

use App\Enums\ApplicationStatus;
use App\Enums\AppointmentStatus;
use App\Enums\DocumentRejectionReason;
use App\Enums\DocumentStatus;
use App\Enums\FineStatus;
use App\Enums\LicenseStatus;
use App\Enums\NotificationType;
use App\Enums\PaymentFailureCode;
use App\Enums\PaymentStatus;
use App\Enums\ProfileStatus;
use App\Enums\TestResultStatus;
use App\Enums\UserType;
use App\Models\ApplicationDocument;
use App\Models\ApplicationStatusHistory;
use App\Models\AppointmentSlot;
use App\Models\AuditLog;
use App\Models\Fee;
use App\Models\Fine;
use App\Models\License;
use App\Models\LicenseApplication;
use App\Models\LicenseStatusHistory;
use App\Models\LicenseType;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentGatewayEvent;
use App\Models\RequiredDocument;
use App\Models\Role;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestResult;
use App\Models\TestType;
use App\Models\User;
use App\Modules\Applications\Support\ServiceWorkflow;
use App\Modules\Payments\Support\ApplicationFeeResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds realistic DLMS records that follow the real application lifecycle:
 * documents → payment → tests (new license only) → issuance / follow-on services.
 */
final class FullLifecycleKit
{
    public const PASSWORD = 'password';

    public const APP_PREFIX = 'FLOW-';

    /** @var list<string> */
    private const FIRST_NAMES = [
        'محمد', 'أحمد', 'علي', 'خالد', 'عمر', 'سامي', 'يوسف', 'حسام', 'ماهر', 'فادي',
        'ليلى', 'سارة', 'نور', 'رنا', 'هند', 'دينا', 'مايا', 'لينا', 'ندى', 'سلمى',
        'باسم', 'وائل', 'زياد', 'طارق', 'بلال', 'إياد', 'رامي', 'وسام', 'غسان', 'كريم',
    ];

    /** @var list<string> */
    private const LAST_NAMES = [
        'الحلبي', 'العلي', 'الحسن', 'الخطيب', 'ناصر', 'حداد', 'عيسى', 'قباني', 'دباغ', 'صباغ',
        'قاسم', 'منلا', 'الأسعد', 'الحموي', 'شوكت', 'جابر', 'بركات', 'صفير', 'عبود', 'حنا',
        'الخوري', 'زيدان', 'العطار', 'طوس', 'الأحمد', 'ياسين', 'رحال', 'مرعي', 'دياب', 'سليمان',
    ];

    /** @var list<string> */
    private const GOVERNORATES = [
        'دمشق', 'ريف دمشق', 'حلب', 'حمص', 'حماة', 'اللاذقية', 'طرطوس',
        'إدلب', 'دير الزور', 'الرقة', 'الحسكة', 'السويداء', 'درعا', 'القنيطرة',
    ];

    /** @var list<string> */
    public const FINE_REASONS = [
        'تجاوز السرعة المحددة على الطريق العام.',
        'الوقوف في مكان ممنوع.',
        'عدم ربط حزام الأمان.',
        'قطع الإشارة الضوئية الحمراء.',
        'استخدام الهاتف أثناء القيادة.',
        'القيادة دون إنارة ليلية كافية.',
        'عدم الالتزام بمسارب الطريق.',
    ];

    private int $citizenSeq = 0;

    /** @var array<string, AppointmentSlot> */
    private array $slotCache = [];

    public function reviewer(): User
    {
        return $this->employeeByRole('profile_document_reviewer')
            ?? $this->employeeByRole('employee')
            ?? throw new RuntimeException('No document reviewer employee is seeded.');
    }

    public function examiner(): User
    {
        return $this->employeeByRole('test_employee')
            ?? $this->reviewer();
    }

    public function issuer(): User
    {
        return $this->employeeByRole('license_employee')
            ?? $this->reviewer();
    }

    public function finesOfficer(): User
    {
        return $this->employeeByRole('fines_employee')
            ?? $this->reviewer();
    }

    public function paymentOfficer(): User
    {
        return $this->employeeByRole('payment_employee')
            ?? $this->reviewer();
    }

    public function ensureHighCapacitySlots(): void
    {
        $centerId = AppointmentSlot::query()->whereNotNull('appointment_center_id')->value('appointment_center_id');
        $past = now()->subDays(40)->toDateString();
        $future = now()->addDays(8)->toDateString();

        foreach (TestType::query()->where('is_active', true)->get() as $testType) {
            $this->upsertSlot($testType, $centerId, $past, '08:00:00', '12:00:00', 500);
            $this->upsertSlot($testType, $centerId, $future, '08:00:00', '12:00:00', 500);
            $this->upsertSlot($testType, $centerId, $future, '13:00:00', '17:00:00', 500);
        }
    }

    /**
     * @param  array{
     *   application_number: string,
     *   service_code?: string,
     *   license_type_code?: string,
     *   status: ApplicationStatus,
     *   submitted_days_ago?: int,
     *   late?: bool,
     *   testing_stage?: string,
     *   payment_variant?: 'none'|'pending'|'failed'|'under_verification'|'completed',
     *   document_variant?: 'none'|'partial'|'all_pending'|'mixed'|'all_approved'|'rejected',
     *   license_status?: LicenseStatus,
     *   print?: bool,
     *   expire?: bool,
     *   block?: bool,
     *   suspend?: bool,
     *   unpaid_fine?: bool,
     *   paid_fine?: bool,
     *   cancelled_fine?: bool,
     * }  $spec
     */
    public function seedScenario(array $spec): LicenseApplication
    {
        $serviceCode = $spec['service_code'] ?? 'new_license';
        $licenseTypeCode = $spec['license_type_code'] ?? 'private';
        $status = $spec['status'];
        $number = $spec['application_number'];

        $this->assertFlowNumber($number);
        $this->resetFlowApplication($number);

        $citizen = $this->upsertCitizen([
            'email' => $this->emailFromNumber($number),
            'name' => $this->nextName(),
            'phone' => $this->nextPhone(),
            'national_id' => $this->nextNationalId(),
            'governorate' => $this->nextGovernorate(),
        ], $licenseTypeCode);

        Fine::query()->where('citizen_id', $citizen->id)->forceDelete();

        $relatedLicenseId = null;
        $originalLicense = null;

        if (ServiceWorkflow::requiresRelatedLicense($serviceCode)) {
            $originalLicense = $this->seedOriginalIssuedLicense(
                $citizen,
                $licenseTypeCode,
                $number,
                $serviceCode === 'license_unblock'
            );
            $relatedLicenseId = $originalLicense->id;
        }

        $createdAt = now()->subDays($spec['submitted_days_ago'] ?? 12)->subHours(6);
        if (($spec['late'] ?? false) === true) {
            $createdAt = now()->subDays(8);
        }

        $application = $this->createApplication(
            $citizen,
            $number,
            $status,
            $serviceCode,
            $licenseTypeCode,
            $relatedLicenseId,
            $createdAt,
        );

        $this->applyLifecycle($application, $citizen, $originalLicense, $spec, $createdAt);

        return $application->fresh([
            'citizen',
            'serviceType',
            'licenseType',
            'applicationDocuments',
            'payments',
            'testAppointments',
            'testResults',
            'license',
            'statusHistories',
        ]) ?? $application;
    }

    /**
     * @param  array{email: string, name: string, phone: string, national_id: string, governorate?: string, address?: string}  $record
     */
    public function upsertCitizen(array $record, string $licenseTypeCode = 'private'): User
    {
        $role = Role::query()->where('name', 'citizen')->firstOrFail();
        $minAge = $licenseTypeCode === 'private' ? 22 : 26;
        $birthDate = now()->subYears($minAge)->subDays(($this->citizenSeq % 200) + 10)->toDateString();
        $governorate = $record['governorate'] ?? $this->nextGovernorate();

        return User::query()->updateOrCreate(
            ['email' => $record['email']],
            [
                'name' => $record['name'],
                'phone' => $record['phone'],
                'national_id' => $record['national_id'],
                'password' => self::PASSWORD,
                'role_id' => $role->id,
                'user_type' => UserType::Citizen,
                'birth_date' => $birthDate,
                'governorate' => $governorate,
                'address' => $record['address'] ?? $governorate.' — حي الأمل — شارع الجمهورية — بناء '.(($this->citizenSeq % 40) + 1),
                'language' => 'ar',
                'theme' => 'system',
                'profile_completed' => true,
                'profile_status' => ProfileStatus::Approved,
                'profile_submitted_at' => now()->subDays(40),
                'profile_reviewed_at' => now()->subDays(38),
                'profile_rejection_reason' => null,
                'is_active' => true,
                'email_verified_at' => now()->subDays(40),
                'phone_verified_at' => now()->subDays(40),
                'deactivated_at' => null,
                'deactivated_by' => null,
                'deactivation_reason' => null,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function seedProfileCitizen(string $slug, ProfileStatus $status, array $overrides = []): User
    {
        $role = Role::query()->where('name', 'citizen')->firstOrFail();
        $this->citizenSeq++;
        $email = 'flow.profile.'.$slug.'@syrtak.local';

        $base = [
            'name' => $this->nextName(),
            'phone' => $this->nextPhone(),
            'national_id' => $this->nextNationalId(),
            'password' => self::PASSWORD,
            'role_id' => $role->id,
            'user_type' => UserType::Citizen,
            'birth_date' => '1994-05-12',
            'governorate' => $this->nextGovernorate(),
            'address' => 'دمشق — المزة — بيانات ملف شخصي',
            'language' => 'ar',
            'profile_completed' => $status !== ProfileStatus::Incomplete,
            'profile_status' => $status,
            'is_active' => true,
            'email_verified_at' => $status === ProfileStatus::Incomplete ? null : now()->subDays(5),
            'phone_verified_at' => now()->subDays(5),
        ];

        if ($status === ProfileStatus::PendingReview) {
            $base['profile_submitted_at'] = now()->subDays(2);
            $base['profile_reviewed_at'] = null;
            $base['profile_rejection_reason'] = null;
        } elseif ($status === ProfileStatus::Rejected) {
            $base['profile_submitted_at'] = now()->subDays(6);
            $base['profile_reviewed_at'] = now()->subDays(4);
            $base['profile_reviewed_by'] = $this->reviewer()->id;
            $base['profile_rejection_reason'] = 'الصورة الشخصية غير مطابقة للهوية. يرجى إعادة رفع صورة أوضح.';
        } elseif ($status === ProfileStatus::Approved) {
            $base['profile_submitted_at'] = now()->subDays(20);
            $base['profile_reviewed_at'] = now()->subDays(18);
            $base['profile_reviewed_by'] = $this->reviewer()->id;
        } else {
            $base['profile_submitted_at'] = null;
            $base['profile_reviewed_at'] = null;
        }

        $citizen = User::query()->updateOrCreate(['email' => $email], array_merge($base, $overrides));

        if ($status === ProfileStatus::Approved) {
            $this->notify($citizen, NotificationType::ProfileApproved, ['profile_status' => 'approved'], 'flow:profile:'.$slug.':approved');
        } elseif ($status === ProfileStatus::Rejected) {
            $this->notify(
                $citizen,
                NotificationType::ProfileRejected,
                [
                    'profile_status' => 'rejected',
                    'rejection_reason' => (string) $citizen->profile_rejection_reason,
                ],
                'flow:profile:'.$slug.':rejected'
            );
        }

        return $citizen;
    }

    public function seedDeactivatedCitizen(string $slug): User
    {
        $admin = User::query()->where('user_type', UserType::Admin)->first() ?? $this->reviewer();
        $citizen = $this->seedProfileCitizen($slug, ProfileStatus::Approved, [
            'is_active' => false,
            'deactivated_at' => now()->subDays(3),
            'deactivated_by' => $admin->id,
            'deactivation_reason' => 'حساب موقوف إدارياً لحين استكمال التحقق من الهوية.',
        ]);

        $this->notify(
            $citizen,
            NotificationType::AccountDeactivated,
            [],
            'flow:profile:'.$slug.':deactivated'
        );

        return $citizen;
    }

    /**
     * @param  array{
     *   application_number: string,
     *   service_code?: string,
     *   license_type_code?: string,
     *   status: ApplicationStatus,
     *   submitted_days_ago?: int,
     *   late?: bool,
     *   testing_stage?: string,
     *   payment_variant?: string,
     *   document_variant?: string,
     *   license_status?: LicenseStatus,
     *   print?: bool,
     *   expire?: bool,
     *   block?: bool,
     *   suspend?: bool,
     *   unpaid_fine?: bool,
     *   paid_fine?: bool,
     *   cancelled_fine?: bool,
     * }  $spec
     */
    private function applyLifecycle(
        LicenseApplication $application,
        User $citizen,
        ?License $relatedLicense,
        array $spec,
        Carbon $createdAt,
    ): void {
        $status = $spec['status'];
        $serviceCode = $application->serviceType?->code ?? 'new_license';
        $requiresTests = ServiceWorkflow::requiresTests($serviceCode);
        $reviewer = $this->reviewer();
        $examiner = $this->examiner();
        $issuer = $this->issuer();

        $clock = $createdAt->copy();
        $this->recordHistory($application, null, ApplicationStatus::Draft, $citizen, $clock, 'إنشاء مسودة الطلب.');
        $this->audit($citizen, 'application.created', 'license_application', $application->id, null, [
            'application_number' => $application->application_number,
            'status' => ApplicationStatus::Draft->value,
        ], $clock);
        $this->notify(
            $citizen,
            NotificationType::ApplicationCreated,
            ['application_id' => $application->id],
            'flow:'.$application->application_number.':created',
            $clock,
        );

        if ($status === ApplicationStatus::Draft) {
            $variant = $spec['document_variant'] ?? 'partial';
            if ($variant !== 'none') {
                $this->attachDocuments($application, $reviewer, $variant, $clock);
            }
            $this->finalizeApplication($application, ApplicationStatus::Draft, $clock);

            return;
        }

        $submittedAt = $clock->copy()->addHours(6);
        $application->submitted_at = $submittedAt;
        $this->recordHistory($application, ApplicationStatus::Draft, ApplicationStatus::DocumentsUnderReview, $citizen, $submittedAt, 'إرسال الوثائق للمراجعة.');
        $this->notifyStatus($application, NotificationType::ApplicationDocumentsUnderReview, $submittedAt);

        if ($status === ApplicationStatus::DocumentsUnderReview) {
            $this->attachDocuments($application, $reviewer, $spec['document_variant'] ?? 'all_pending', $submittedAt);
            $this->finalizeApplication($application, ApplicationStatus::DocumentsUnderReview, $submittedAt);

            return;
        }

        if ($status === ApplicationStatus::DocumentsRejected) {
            $this->attachDocuments($application, $reviewer, $spec['document_variant'] ?? 'rejected', $submittedAt);
            $rejectedAt = $submittedAt->copy()->addHours(10);
            $reason = 'إحدى الوثائق المرفوعة غير واضحة أو غير مطابقة للمتطلبات.';
            $application->rejection_reason = $reason;
            $this->recordHistory($application, ApplicationStatus::DocumentsUnderReview, ApplicationStatus::DocumentsRejected, $reviewer, $rejectedAt, $reason);
            $this->notifyStatus($application, NotificationType::ApplicationDocumentsRejected, $rejectedAt);
            $this->finalizeApplication($application, ApplicationStatus::DocumentsRejected, $rejectedAt);

            return;
        }

        if ($status === ApplicationStatus::Rejected) {
            $this->attachDocuments($application, $reviewer, 'mixed', $submittedAt);
            $rejectedAt = $submittedAt->copy()->addHours(12);
            $reason = $spec['rejection_reason'] ?? 'الطلب لا يستوفي الشروط النظامية بعد المراجعة.';
            $application->rejection_reason = $reason;
            $this->recordHistory($application, ApplicationStatus::DocumentsUnderReview, ApplicationStatus::Rejected, $reviewer, $rejectedAt, $reason);
            $this->notifyStatus($application, NotificationType::ApplicationRejected, $rejectedAt);
            $this->finalizeApplication($application, ApplicationStatus::Rejected, $rejectedAt);

            return;
        }

        if ($status === ApplicationStatus::Cancelled) {
            $this->attachDocuments($application, $reviewer, 'all_pending', $submittedAt);
            $cancelledAt = $submittedAt->copy()->addHours(8);
            $this->recordHistory($application, ApplicationStatus::DocumentsUnderReview, ApplicationStatus::Cancelled, $citizen, $cancelledAt, 'ألغى المواطن الطلب قبل استكمال المراجعة.');
            $this->notifyStatus($application, NotificationType::ApplicationCancelled, $cancelledAt);
            $this->finalizeApplication($application, ApplicationStatus::Cancelled, $cancelledAt);

            return;
        }

        $this->attachDocuments($application, $reviewer, $spec['document_variant'] ?? 'all_approved', $submittedAt);
        $paymentPendingAt = $submittedAt->copy()->addHours(18);
        $this->recordHistory($application, ApplicationStatus::DocumentsUnderReview, ApplicationStatus::PaymentPending, $reviewer, $paymentPendingAt, 'اعتماد الوثائق والانتقال إلى الدفع.');
        $this->notifyStatus($application, NotificationType::ApplicationPaymentPending, $paymentPendingAt);

        if ($status === ApplicationStatus::PaymentPending) {
            $this->attachPayment($application, $citizen, $spec['payment_variant'] ?? 'pending', $paymentPendingAt);
            $this->finalizeApplication($application, ApplicationStatus::PaymentPending, $paymentPendingAt);

            return;
        }

        if ($status === ApplicationStatus::AdministrativeReview && ! $requiresTests) {
            $this->attachPayment($application, $citizen, 'completed', $paymentPendingAt);
            $adminAt = $paymentPendingAt->copy()->addHours(6);
            $this->recordHistory($application, ApplicationStatus::PaymentPending, ApplicationStatus::AdministrativeReview, $reviewer, $adminAt, 'تحويل الطلب لمراجعة إدارية.');
            $this->notifyStatus($application, NotificationType::ApplicationAdministrativeReview, $adminAt);
            $this->finalizeApplication($application, ApplicationStatus::AdministrativeReview, $adminAt);

            return;
        }

        $this->attachPayment($application, $citizen, $spec['payment_variant'] ?? 'completed', $paymentPendingAt);
        $paidAt = $paymentPendingAt->copy()->addHours(5);
        $this->recordHistory($application, ApplicationStatus::PaymentPending, ApplicationStatus::PaymentCompleted, $citizen, $paidAt, 'اكتمال دفع رسوم الخدمة.');

        if ($status === ApplicationStatus::PaymentCompleted) {
            $this->finalizeApplication($application, ApplicationStatus::PaymentCompleted, $paidAt);

            return;
        }

        $afterPay = $requiresTests ? ApplicationStatus::AppointmentPending : ApplicationStatus::Approved;
        $afterPayAt = $paidAt->copy()->addMinutes(20);
        $this->recordHistory(
            $application,
            ApplicationStatus::PaymentCompleted,
            $afterPay,
            null,
            $afterPayAt,
            $requiresTests ? 'فتح حجز اختبار النظر.' : 'الطلب جاهز للإصدار بعد الدفع.'
        );

        if ($afterPay === ApplicationStatus::AppointmentPending) {
            $this->notifyStatus($application, NotificationType::ApplicationAppointmentPending, $afterPayAt);
        } else {
            $application->approved_at = $afterPayAt;
            $this->notifyStatus($application, NotificationType::ApplicationApproved, $afterPayAt);
        }

        if ($status === ApplicationStatus::AppointmentPending) {
            $application->current_test_type_id = $this->testType('vision')->id;
            $this->finalizeApplication($application, ApplicationStatus::AppointmentPending, $afterPayAt);

            return;
        }

        if (! $requiresTests) {
            $this->completeNonTestStatuses($application, $citizen, $relatedLicense, $spec, $status, $afterPayAt, $issuer);

            return;
        }

        $this->completeTestingStatuses($application, $citizen, $spec, $status, $afterPayAt, $examiner, $issuer);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function completeNonTestStatuses(
        LicenseApplication $application,
        User $citizen,
        ?License $relatedLicense,
        array $spec,
        ApplicationStatus $status,
        Carbon $approvedAt,
        User $issuer,
    ): void {
        if ($status === ApplicationStatus::Approved) {
            $application->approved_at = $approvedAt;
            $this->attachOptionalFines($citizen, $relatedLicense, $spec);
            $this->finalizeApplication($application, ApplicationStatus::Approved, $approvedAt);

            return;
        }

        if ($status === ApplicationStatus::LicenseIssued) {
            $issuedAt = $approvedAt->copy()->addHours(10);
            $application->approved_at = $approvedAt;
            $application->issued_at = $issuedAt;
            $this->finalizeApplication($application, ApplicationStatus::LicenseIssued, $issuedAt);
            $this->issueFromApplication($application, $citizen, $relatedLicense, $issuer, $issuedAt, $spec);
            $this->attachOptionalFines($citizen, $application->fresh()?->license, $spec);

            return;
        }

        if ($status === ApplicationStatus::Completed) {
            $completedAt = $approvedAt->copy()->addHours(10);
            $application->approved_at = $approvedAt;
            $this->recordHistory($application, ApplicationStatus::Approved, ApplicationStatus::Completed, $issuer, $completedAt, 'تم فك حظر الرخصة وإكمال الطلب.');
            $this->notifyStatus($application, NotificationType::ApplicationCompleted, $completedAt);
            $this->finalizeApplication($application, ApplicationStatus::Completed, $completedAt);
            $this->issueFromApplication($application, $citizen, $relatedLicense, $issuer, $completedAt, $spec);

            return;
        }

        $this->finalizeApplication($application, $status, $approvedAt);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function completeTestingStatuses(
        LicenseApplication $application,
        User $citizen,
        array $spec,
        ApplicationStatus $status,
        Carbon $appointmentPendingAt,
        User $examiner,
        User $issuer,
    ): void {
        $stage = $spec['testing_stage'] ?? 'vision';

        if ($status === ApplicationStatus::InTesting) {
            if (($spec['prior_cancelled_appointment'] ?? false) === true) {
                $this->createCancelledAppointment(
                    $application,
                    $citizen,
                    $stage === 'practical' ? 'practical' : 'vision',
                    $appointmentPendingAt->copy()->addHours(6)
                );
            }

            if ($stage === 'theory') {
                $this->completePassedTest($application, $citizen, $examiner, 'vision', $appointmentPendingAt->copy()->addDays(2));
                $this->bookWaitingAppointment($application, $citizen, 'theory');
                $application->current_test_type_id = $this->testType('theory')->id;
            } elseif ($stage === 'practical') {
                $this->completePassedTest($application, $citizen, $examiner, 'vision', $appointmentPendingAt->copy()->addDays(2));
                $this->completePassedTest($application, $citizen, $examiner, 'theory', $appointmentPendingAt->copy()->addDays(5));
                $this->bookWaitingAppointment($application, $citizen, 'practical');
                $application->current_test_type_id = $this->testType('practical')->id;
            } else {
                $this->bookWaitingAppointment($application, $citizen, 'vision');
                $application->current_test_type_id = $this->testType('vision')->id;
            }

            $testingAt = $appointmentPendingAt->copy()->addDays(1);
            $this->recordHistory($application, ApplicationStatus::AppointmentPending, ApplicationStatus::InTesting, $citizen, $testingAt, 'حجز موعد الاختبار الحالي.');
            $this->finalizeApplication($application, ApplicationStatus::InTesting, $testingAt);

            return;
        }

        if ($status === ApplicationStatus::WaitingRetest) {
            $failAt = $appointmentPendingAt->copy()->addDays(3);
            $result = ($spec['retest_result'] ?? 'failed') === 'no_show'
                ? TestResultStatus::NoShow
                : TestResultStatus::Failed;
            $this->completeAttemptedTest($application, $citizen, $examiner, 'vision', $result, 1, $failAt);
            $this->bookWaitingAppointment($application, $citizen, 'vision');
            $application->current_test_type_id = $this->testType('vision')->id;
            $waitingAt = $failAt->copy()->addHours(3);
            $this->recordHistory($application, ApplicationStatus::InTesting, ApplicationStatus::WaitingRetest, $examiner, $waitingAt, 'رسوب/غياب في اختبار النظر — بانتظار إعادة الاختبار.');
            $this->notifyStatus($application, NotificationType::ApplicationWaitingRetest, $waitingAt);
            $this->finalizeApplication($application, ApplicationStatus::WaitingRetest, $waitingAt);

            return;
        }

        if ($status === ApplicationStatus::AdministrativeReview) {
            $this->completeAttemptedTest($application, $citizen, $examiner, 'vision', TestResultStatus::Failed, 1, $appointmentPendingAt->copy()->addDays(2));
            $this->completeAttemptedTest($application, $citizen, $examiner, 'vision', TestResultStatus::Failed, 2, $appointmentPendingAt->copy()->addDays(8));
            $this->completeAttemptedTest($application, $citizen, $examiner, 'vision', TestResultStatus::NoShow, 3, $appointmentPendingAt->copy()->addDays(14));
            $adminAt = $appointmentPendingAt->copy()->addDays(14)->addHours(4);
            $application->current_test_type_id = $this->testType('vision')->id;
            $this->recordHistory($application, ApplicationStatus::WaitingRetest, ApplicationStatus::AdministrativeReview, $examiner, $adminAt, 'استنفاد محاولات اختبار النظر.');
            $this->notifyStatus($application, NotificationType::ApplicationAdministrativeReview, $adminAt);
            $this->finalizeApplication($application, ApplicationStatus::AdministrativeReview, $adminAt);

            return;
        }

        $this->completePassedTest($application, $citizen, $examiner, 'vision', $appointmentPendingAt->copy()->addDays(2));
        $this->completePassedTest($application, $citizen, $examiner, 'theory', $appointmentPendingAt->copy()->addDays(6));
        $this->completePassedTest($application, $citizen, $examiner, 'practical', $appointmentPendingAt->copy()->addDays(10));

        $approvedAt = $appointmentPendingAt->copy()->addDays(10)->addHours(4);
        $application->current_test_type_id = null;
        $application->approved_at = $approvedAt;
        $this->recordHistory($application, ApplicationStatus::InTesting, ApplicationStatus::Approved, $examiner, $approvedAt, 'اجتياز جميع الاختبارات.');
        $this->notifyStatus($application, NotificationType::ApplicationApproved, $approvedAt);

        if ($status === ApplicationStatus::Approved) {
            $this->attachOptionalFines($citizen, null, $spec);
            $this->finalizeApplication($application, ApplicationStatus::Approved, $approvedAt);

            return;
        }

        if ($status === ApplicationStatus::LicenseIssued) {
            $issuedAt = $approvedAt->copy()->addHours(8);
            $application->issued_at = $issuedAt;
            $this->finalizeApplication($application, ApplicationStatus::LicenseIssued, $issuedAt);
            $this->issueFromApplication($application, $citizen, null, $issuer, $issuedAt, $spec);
            $this->attachOptionalFines($citizen, $application->fresh()?->license, $spec);

            return;
        }

        $this->finalizeApplication($application, $status, $approvedAt);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function issueFromApplication(
        LicenseApplication $application,
        User $citizen,
        ?License $relatedLicense,
        User $issuer,
        Carbon $issuedAt,
        array $spec,
    ): License {
        $serviceCode = $application->serviceType?->code;
        $status = $spec['license_status'] ?? LicenseStatus::Active;
        $validityYears = (int) ($application->licenseType?->validity_years ?: config('license.validity_years', 10));

        $issueDate = $issuedAt->toDateString();
        $expiryDate = $issuedAt->copy()->addYears($validityYears)->toDateString();
        $previousId = null;

        if ($relatedLicense !== null && $serviceCode === 'renew_license') {
            $previousId = $relatedLicense->id;
            $from = $relatedLicense->status;
            $relatedLicense->status = LicenseStatus::Renewed;
            $relatedLicense->save();
            $this->licenseHistory($relatedLicense, 'renewed', $from, LicenseStatus::Renewed, $issuer, $issuedAt, 'issuance');
        } elseif ($relatedLicense !== null && in_array($serviceCode, ['lost_replacement', 'damaged_replacement'], true)) {
            $previousId = $relatedLicense->id;
            $from = $relatedLicense->status;
            $relatedLicense->status = LicenseStatus::Inactive;
            $relatedLicense->save();
            $this->licenseHistory($relatedLicense, 'replaced', $from, LicenseStatus::Inactive, $issuer, $issuedAt, 'issuance');
            $expiryDate = $relatedLicense->expiry_date?->toDateString() ?? $expiryDate;
        } elseif ($relatedLicense !== null && $serviceCode === 'license_unblock') {
            $relatedLicense->status = LicenseStatus::Active;
            $relatedLicense->blocked_at = null;
            $relatedLicense->blocked_by = null;
            $relatedLicense->block_reason = null;
            $relatedLicense->save();
            $this->licenseHistory($relatedLicense, 'unblocked', LicenseStatus::Blocked, LicenseStatus::Active, $issuer, $issuedAt, 'dashboard');
            $this->notify(
                $citizen,
                NotificationType::LicenseUnblocked,
                ['license_id' => $relatedLicense->id, 'license_number' => $relatedLicense->license_number],
                'flow:'.$application->application_number.':unblocked',
                $issuedAt,
            );

            return $relatedLicense;
        }

        if (($spec['expire'] ?? false) === true) {
            $status = LicenseStatus::Expired;
            $issueDate = $issuedAt->copy()->subYears($validityYears + 1)->toDateString();
            $expiryDate = $issuedAt->copy()->subMonths(4)->toDateString();
        }

        $license = License::query()->updateOrCreate(
            ['license_number' => 'LIC-'.$application->application_number],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $application->license_type_id,
                'application_id' => $application->id,
                'issued_by' => $issuer->id,
                'previous_license_id' => $previousId,
                'status' => $status,
                'issue_date' => $issueDate,
                'expiry_date' => $expiryDate,
                'verification_token' => substr(hash('sha256', 'flow-token-'.$application->application_number), 0, 48),
                'print_count' => 0,
                'printed_at' => null,
                'printed_by' => null,
                'blocked_at' => null,
                'blocked_by' => null,
                'block_reason' => null,
                'deleted_at' => null,
            ]
        );

        $this->licenseHistory($license, 'issued', null, LicenseStatus::Active, $issuer, $issuedAt, 'issuance');
        $this->audit($issuer, 'license.issued', 'license', $license->id, null, [
            'license_number' => $license->license_number,
            'application_id' => $application->id,
            'status' => LicenseStatus::Active->value,
        ], $issuedAt);
        $this->notify(
            $citizen,
            NotificationType::LicenseIssued,
            ['application_id' => $application->id, 'license_id' => $license->id],
            'flow:'.$application->application_number.':license-issued',
            $issuedAt,
        );

        if (($spec['print'] ?? false) === true) {
            $license->print_count = 2;
            $license->printed_at = $issuedAt->copy()->addDays(1);
            $license->printed_by = $issuer->id;
            $license->save();
            $this->audit($issuer, 'license.printed', 'license', $license->id, null, [
                'print_count' => 2,
            ], $issuedAt->copy()->addDays(1));
        }

        if (($spec['expire'] ?? false) === true) {
            $this->licenseHistory($license, 'expired', LicenseStatus::Active, LicenseStatus::Expired, null, Carbon::parse($expiryDate)->addDay(), 'scheduler');
            $this->notify(
                $citizen,
                NotificationType::LicenseExpired,
                ['license_id' => $license->id, 'license_number' => $license->license_number],
                'flow:'.$application->application_number.':expired',
            );
        }

        if (($spec['block'] ?? false) === true) {
            $blockedAt = $issuedAt->copy()->addDays(20);
            $license->status = LicenseStatus::Blocked;
            $license->blocked_at = $blockedAt;
            $license->blocked_by = $this->finesOfficer()->id;
            $license->block_reason = 'حظر إداري بسبب مخالفات متكررة.';
            $license->save();
            $this->licenseHistory($license, 'blocked', LicenseStatus::Active, LicenseStatus::Blocked, $this->finesOfficer(), $blockedAt, 'dashboard', $license->block_reason);
            $this->notify(
                $citizen,
                NotificationType::LicenseBlocked,
                ['license_id' => $license->id, 'license_number' => $license->license_number],
                'flow:'.$application->application_number.':blocked',
                $blockedAt,
            );
        }

        if (($spec['suspend'] ?? false) === true) {
            $license->status = LicenseStatus::Suspended;
            $license->save();
            $this->licenseHistory($license, 'suspended', LicenseStatus::Active, LicenseStatus::Suspended, $issuer, $issuedAt->copy()->addDays(12), 'dashboard', 'إيقاف مؤقت بقرار إداري.');
        }

        return $license->fresh() ?? $license;
    }

    private function seedOriginalIssuedLicense(
        User $citizen,
        string $licenseTypeCode,
        string $followOnNumber,
        bool $blocked,
    ): License {
        $origNumber = 'FLOW-ORIG-'.substr($followOnNumber, strlen(self::APP_PREFIX));
        $this->resetFlowApplication($origNumber);

        $issuedAt = now()->subYears(3);
        $orig = $this->createApplication(
            $citizen,
            $origNumber,
            ApplicationStatus::LicenseIssued,
            'new_license',
            $licenseTypeCode,
            null,
            $issuedAt->copy()->subDays(20),
        );
        $orig->submitted_at = $issuedAt->copy()->subDays(18);
        $orig->approved_at = $issuedAt->copy()->subDays(2);
        $orig->issued_at = $issuedAt;
        $orig->save();

        $this->attachDocuments($orig, $this->reviewer(), 'all_approved', $orig->submitted_at);
        $this->attachPayment($orig, $citizen, 'completed', $issuedAt->copy()->subDays(10));
        $this->completePassedTest($orig, $citizen, $this->examiner(), 'vision', $issuedAt->copy()->subDays(8));
        $this->completePassedTest($orig, $citizen, $this->examiner(), 'theory', $issuedAt->copy()->subDays(6));
        $this->completePassedTest($orig, $citizen, $this->examiner(), 'practical', $issuedAt->copy()->subDays(4));
        $this->recordHistory($orig, null, ApplicationStatus::LicenseIssued, $this->issuer(), $issuedAt, 'إصدار الرخصة الأصلية المرتبطة بالخدمة اللاحقة.');

        $licenseType = LicenseType::query()->where('code', $licenseTypeCode)->firstOrFail();
        $validity = (int) ($licenseType->validity_years ?: 5);
        $status = $blocked ? LicenseStatus::Blocked : LicenseStatus::Active;

        $license = License::query()->updateOrCreate(
            ['license_number' => 'LIC-'.$origNumber],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'application_id' => $orig->id,
                'issued_by' => $this->issuer()->id,
                'previous_license_id' => null,
                'status' => $status,
                'issue_date' => $issuedAt->toDateString(),
                'expiry_date' => $issuedAt->copy()->addYears($validity)->toDateString(),
                'verification_token' => substr(hash('sha256', 'flow-orig-'.$origNumber), 0, 48),
                'print_count' => 1,
                'printed_at' => $issuedAt->copy()->addDay(),
                'printed_by' => $this->issuer()->id,
                'blocked_at' => $blocked ? $issuedAt->copy()->addYear() : null,
                'blocked_by' => $blocked ? $this->finesOfficer()->id : null,
                'block_reason' => $blocked ? 'حظر إداري — بانتظار طلب فك الحظر.' : null,
                'deleted_at' => null,
            ]
        );

        $this->licenseHistory($license, 'issued', null, LicenseStatus::Active, $this->issuer(), $issuedAt, 'issuance');
        if ($blocked) {
            $this->licenseHistory(
                $license,
                'blocked',
                LicenseStatus::Active,
                LicenseStatus::Blocked,
                $this->finesOfficer(),
                $issuedAt->copy()->addYear(),
                'dashboard',
                $license->block_reason,
            );
        }

        return $license;
    }

    private function createApplication(
        User $citizen,
        string $number,
        ApplicationStatus $status,
        string $serviceCode,
        string $licenseTypeCode,
        ?int $relatedLicenseId,
        Carbon $createdAt,
    ): LicenseApplication {
        $licenseType = LicenseType::query()->where('code', $licenseTypeCode)->firstOrFail();
        $serviceType = ServiceType::query()->where('code', $serviceCode)->firstOrFail();

        $application = LicenseApplication::query()->updateOrCreate(
            ['application_number' => $number],
            [
                'citizen_id' => $citizen->id,
                'license_type_id' => $licenseType->id,
                'service_type_id' => $serviceType->id,
                'related_license_id' => $relatedLicenseId,
                'status' => $status,
                'current_test_type_id' => null,
                'rejection_reason' => null,
                'submitted_at' => null,
                'approved_at' => null,
                'issued_at' => null,
                'deleted_at' => null,
            ]
        );

        $application->created_at = $createdAt;
        $application->updated_at = $createdAt;
        $application->save();

        return $application->fresh(['serviceType', 'licenseType']) ?? $application;
    }

    private function attachDocuments(
        LicenseApplication $application,
        User $reviewer,
        string $variant,
        Carbon $when,
    ): void {
        $required = $this->requiredDocumentsFor($application);
        if ($required->isEmpty()) {
            return;
        }

        $reasons = DocumentRejectionReason::cases();
        $index = 0;

        foreach ($required as $document) {
            $status = match ($variant) {
                'none' => null,
                'partial' => $index === 0 ? DocumentStatus::PendingReview : null,
                'all_pending' => DocumentStatus::PendingReview,
                'mixed' => $index === 0
                    ? DocumentStatus::Approved
                    : ($index === 1 ? DocumentStatus::PendingReview : DocumentStatus::PendingReview),
                'rejected' => $index === 0
                    ? DocumentStatus::Approved
                    : ($index === 1 ? DocumentStatus::Rejected : DocumentStatus::PendingReview),
                default => DocumentStatus::Approved,
            };

            $index++;
            if ($status === null) {
                continue;
            }

            $path = $this->putDemoFile($application, $document);
            $rejected = $status === DocumentStatus::Rejected;
            $reason = $reasons[($index - 1) % count($reasons)];
            $details = $rejected && $reason === DocumentRejectionReason::Other
                ? 'الصورة مقصوصة ولا تظهر كامل الوثيقة.'
                : null;

            $uploaded = ApplicationDocument::withTrashed()->updateOrCreate(
                [
                    'application_id' => $application->id,
                    'required_document_id' => $document->id,
                ],
                [
                    'file_path' => $path,
                    'original_name' => $document->name.'.pdf',
                    'mime_type' => 'application/pdf',
                    'size' => Storage::disk('local')->size($path),
                    'status' => $status,
                    'rejection_reason' => $rejected ? $reason->displayReason($details) : null,
                    'rejection_reason_code' => $rejected ? $reason->value : null,
                    'rejection_details' => $details,
                    'reviewed_by' => $status === DocumentStatus::PendingReview ? null : $reviewer->id,
                    'reviewed_at' => $status === DocumentStatus::PendingReview ? null : $when->copy()->addHours(8),
                    'deleted_at' => null,
                ]
            );

            $application->loadMissing('citizen');
            if ($application->citizen === null) {
                continue;
            }

            if ($status === DocumentStatus::Approved) {
                $this->notify(
                    $application->citizen,
                    NotificationType::DocumentApproved,
                    ['application_id' => $application->id, 'document_id' => $uploaded->id],
                    'flow:'.$application->application_number.':doc-approved:'.$document->code,
                    $when,
                );
            } elseif ($rejected) {
                $this->notify(
                    $application->citizen,
                    NotificationType::DocumentRejected,
                    [
                        'application_id' => $application->id,
                        'document_id' => $uploaded->id,
                        'rejection_reason_code' => $reason->value,
                        'rejection_reason_label' => $reason->label(),
                        'rejection_details' => $details,
                    ],
                    'flow:'.$application->application_number.':doc-rejected:'.$document->code,
                    $when,
                );
            }
        }
    }

    private function attachPayment(
        LicenseApplication $application,
        User $citizen,
        string $variant,
        Carbon $when,
    ): void {
        if ($variant === 'none') {
            return;
        }

        $fee = app(ApplicationFeeResolver::class)->resolve($application);
        $obligation = Payment::obligationKey($application->id, $fee->id);

        if (in_array($variant, ['failed', 'pending', 'under_verification'], true)) {
            $failed = $this->writePayment(
                $application,
                $citizen,
                $fee,
                $application->application_number.'-PAY-FAIL',
                PaymentStatus::Failed,
                $when->copy()->subHours(2),
                [
                    'failure_code' => PaymentFailureCode::AsyncPaymentFailed->value,
                    'failure_message' => 'تعذر إتمام الدفع لدى مزود الخدمة.',
                    'failed_at' => $when->copy()->subHours(2),
                    'provider_reference' => $application->application_number.'-FAIL',
                    'settled_obligation_key' => null,
                    'active_obligation_key' => null,
                ]
            );
            $this->notify(
                $citizen,
                NotificationType::PaymentFailed,
                [
                    'application_id' => $application->id,
                    'payment_id' => $failed->id,
                    'payment_number' => $failed->payment_number,
                ],
                'flow:'.$application->application_number.':pay-failed',
                $when,
            );
        }

        if ($variant === 'failed') {
            $this->writePayment(
                $application,
                $citizen,
                $fee,
                $application->application_number.'-PAY',
                PaymentStatus::Pending,
                $when,
                [
                    'provider_reference' => $application->application_number.'-PENDING',
                    'active_obligation_key' => $obligation,
                    'settled_obligation_key' => null,
                ]
            );

            return;
        }

        if ($variant === 'pending' || $variant === 'under_verification') {
            $status = $variant === 'under_verification'
                ? PaymentStatus::UnderVerification
                : PaymentStatus::Pending;
            $payment = $this->writePayment(
                $application,
                $citizen,
                $fee,
                $application->application_number.'-PAY',
                $status,
                $when,
                [
                    'provider_reference' => $application->application_number.'-'.$status->value,
                    'active_obligation_key' => $obligation,
                    'settled_obligation_key' => null,
                    'last_verified_at' => $status === PaymentStatus::UnderVerification ? $when : null,
                ]
            );

            if ($status === PaymentStatus::UnderVerification) {
                $this->notify(
                    $citizen,
                    NotificationType::PaymentUnderVerification,
                    [
                        'application_id' => $application->id,
                        'payment_id' => $payment->id,
                        'payment_number' => $payment->payment_number,
                    ],
                    'flow:'.$application->application_number.':pay-verifying',
                    $when,
                );
            }

            return;
        }

        $payment = $this->writePayment(
            $application,
            $citizen,
            $fee,
            $application->application_number.'-PAY',
            PaymentStatus::Completed,
            $when,
            [
                'paid_at' => $when,
                'provider_reference' => $application->application_number.'-PAID',
                'settled_obligation_key' => $obligation,
                'active_obligation_key' => null,
            ]
        );

        PaymentGatewayEvent::query()->updateOrCreate(
            [
                'provider' => 'mock',
                'event_id' => 'evt-'.$application->application_number,
            ],
            [
                'event_type' => 'checkout.session.completed',
                'payment_id' => $payment->id,
                'processing_status' => 'processed',
                'payload_hash' => hash('sha256', $application->application_number),
                'received_at' => $when,
                'processed_at' => $when,
            ]
        );

        $this->notify(
            $citizen,
            NotificationType::PaymentCompleted,
            [
                'application_id' => $application->id,
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'amount' => (string) $fee->amount,
                'currency' => (string) $fee->currency,
            ],
            'flow:'.$application->application_number.':pay-completed',
            $when,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function writePayment(
        LicenseApplication $application,
        User $citizen,
        Fee $fee,
        string $paymentNumber,
        PaymentStatus $status,
        Carbon $when,
        array $extra = [],
    ): Payment {
        return Payment::withTrashed()->updateOrCreate(
            ['payment_number' => $paymentNumber],
            array_merge([
                'user_id' => $citizen->id,
                'application_id' => $application->id,
                'fine_id' => null,
                'fee_id' => $fee->id,
                'payable_type' => Fee::class,
                'payable_id' => $fee->id,
                'amount' => $fee->amount,
                'currency' => $fee->currency,
                'status' => $status,
                'provider' => 'mock',
                'provider_reference' => $paymentNumber,
                'paid_at' => $status === PaymentStatus::Completed ? $when : null,
                'metadata' => ['source' => 'full_lifecycle_seeder'],
                'failure_code' => null,
                'failure_message' => null,
                'failed_at' => null,
                'last_verified_at' => null,
                'settled_obligation_key' => null,
                'active_obligation_key' => null,
                'deleted_at' => null,
                'created_at' => $when,
                'updated_at' => $when,
            ], $extra)
        );
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function attachOptionalFines(User $citizen, ?License $license, array $spec): void
    {
        if (($spec['unpaid_fine'] ?? false) === true) {
            $this->createFine($citizen, $license, FineStatus::Unpaid, 25.00, self::FINE_REASONS[0]);
        }
        if (($spec['paid_fine'] ?? false) === true) {
            $fine = $this->createFine($citizen, $license, FineStatus::Paid, 15.00, self::FINE_REASONS[1], now()->subDays(4));
            Payment::withTrashed()->updateOrCreate(
                ['payment_number' => 'PAY-FINE-'.$fine->id],
                [
                    'user_id' => $citizen->id,
                    'application_id' => null,
                    'fine_id' => $fine->id,
                    'fee_id' => null,
                    'payable_type' => Fine::class,
                    'payable_id' => $fine->id,
                    'amount' => $fine->amount,
                    'currency' => strtoupper((string) config('payment.fine_currency', 'USD')),
                    'status' => PaymentStatus::Completed,
                    'provider' => 'mock',
                    'provider_reference' => 'FINE-'.$fine->id,
                    'paid_at' => $fine->paid_at,
                    'metadata' => ['source' => 'full_lifecycle_seeder', 'kind' => 'fine'],
                    'deleted_at' => null,
                ]
            );
        }
        if (($spec['cancelled_fine'] ?? false) === true) {
            $this->createFine($citizen, $license, FineStatus::Cancelled, 10.00, self::FINE_REASONS[2]);
        }
    }

    public function createFine(
        User $citizen,
        ?License $license,
        FineStatus $status,
        int|float $amount,
        string $reason,
        ?Carbon $paidAt = null,
    ): Fine {
        $fine = Fine::query()->create([
            'citizen_id' => $citizen->id,
            'license_id' => $license?->id,
            'amount' => $amount,
            'currency' => strtoupper((string) config('payment.fine_currency', 'USD')),
            'reason' => $reason,
            'status' => $status,
            'paid_at' => $status === FineStatus::Paid ? ($paidAt ?? now()->subDays(2)) : null,
        ]);

        $type = match ($status) {
            FineStatus::Unpaid => NotificationType::FineCreated,
            FineStatus::Paid => NotificationType::FinePaid,
            FineStatus::Cancelled => NotificationType::FineCancelled,
        };

        $this->notify($citizen, $type, ['fine_id' => $fine->id], 'flow:fine:'.$fine->id.':'.$status->value);

        return $fine;
    }

    private function completePassedTest(
        LicenseApplication $application,
        User $citizen,
        User $examiner,
        string $testTypeCode,
        Carbon $when,
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
    ): void {
        $testType = $this->testType($testTypeCode);
        $slot = $this->slotFor($testType, preferPast: true);
        $appointmentStatus = $result === TestResultStatus::NoShow
            ? AppointmentStatus::NoShow
            : AppointmentStatus::Completed;

        $appointment = TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => $appointmentStatus,
            'scheduled_at' => $when,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);

        $testResult = TestResult::query()->create([
            'application_id' => $application->id,
            'test_appointment_id' => $appointment->id,
            'test_type_id' => $testType->id,
            'result' => $result,
            'attempt_number' => $attemptNumber,
            'notes' => match ($result) {
                TestResultStatus::Passed => 'اجتياز اختبار '.$testType->name.' من المحاولة رقم '.$attemptNumber.'.',
                TestResultStatus::Failed => 'رسوب في اختبار '.$testType->name.' — المحاولة '.$attemptNumber.'.',
                TestResultStatus::NoShow => 'لم يحضر المواطن لاختبار '.$testType->name.'.',
                default => null,
            },
            'recorded_by' => $examiner->id,
            'recorded_at' => $when->copy()->addHours(2),
        ]);

        $notifyType = NotificationType::fromTestResultStatus($result);
        $this->notify(
            $citizen,
            $notifyType,
            ['application_id' => $application->id, 'test_result_id' => $testResult->id],
            'flow:'.$application->application_number.':test:'.$testTypeCode.':'.$attemptNumber,
            $when,
        );
    }

    public function createCancelledAppointment(
        LicenseApplication $application,
        User $citizen,
        string $testTypeCode,
        Carbon $when,
    ): TestAppointment {
        $testType = $this->testType($testTypeCode);
        $slot = $this->slotFor($testType, preferPast: true);

        $appointment = TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Cancelled,
            'scheduled_at' => $when,
            'cancelled_at' => $when->copy()->subHours(4),
            'cancellation_reason' => 'اعتذار المواطن عن الموعد وإعادة الجدولة.',
        ]);

        $this->notify(
            $citizen,
            NotificationType::AppointmentCancelled,
            [
                'application_id' => $application->id,
                'appointment_id' => $appointment->id,
                'test_type_id' => $testType->id,
            ],
            'flow:'.$application->application_number.':appt-cancelled:'.$appointment->id,
            $when,
        );

        return $appointment;
    }

    private function bookWaitingAppointment(
        LicenseApplication $application,
        User $citizen,
        string $testTypeCode,
    ): TestAppointment {
        $testType = $this->testType($testTypeCode);
        $slot = $this->slotFor($testType, preferPast: false);

        $appointment = TestAppointment::query()->create([
            'application_id' => $application->id,
            'citizen_id' => $citizen->id,
            'appointment_slot_id' => $slot->id,
            'test_type_id' => $testType->id,
            'status' => AppointmentStatus::Booked,
            'scheduled_at' => Carbon::parse($slot->date?->format('Y-m-d').' '.(string) $slot->start_time),
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);

        $slot->increment('booked_count');

        $this->notify(
            $citizen,
            NotificationType::AppointmentBooked,
            [
                'application_id' => $application->id,
                'appointment_id' => $appointment->id,
                'test_type_id' => $testType->id,
            ],
            'flow:'.$application->application_number.':appt-booked:'.$appointment->id,
        );

        return $appointment;
    }

    private function finalizeApplication(LicenseApplication $application, ApplicationStatus $status, Carbon $when): void
    {
        $application->status = $status;
        $application->updated_at = $when;
        $application->save();
    }

    private function recordHistory(
        LicenseApplication $application,
        ?ApplicationStatus $from,
        ApplicationStatus $to,
        ?User $actor,
        Carbon $when,
        string $notes,
    ): ApplicationStatusHistory {
        $history = ApplicationStatusHistory::query()->create([
            'application_id' => $application->id,
            'old_status' => $from,
            'new_status' => $to,
            'changed_by' => $actor?->id,
            'reason' => null,
            'notes' => $notes,
        ]);
        $history->created_at = $when;
        $history->updated_at = $when;
        $history->save();

        $this->audit($actor, 'application.status_changed', 'license_application', $application->id, [
            'status' => $from?->value,
        ], [
            'status' => $to->value,
            'notes' => $notes,
        ], $when);

        return $history;
    }

    private function licenseHistory(
        License $license,
        string $action,
        ?LicenseStatus $from,
        LicenseStatus $to,
        ?User $actor,
        Carbon $when,
        string $source,
        ?string $reason = null,
    ): void {
        $row = LicenseStatusHistory::query()->create([
            'license_id' => $license->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'action' => $action,
            'reason' => $reason,
            'performed_by' => $actor?->id,
            'source' => $source,
            'metadata' => ['seeder' => 'full_lifecycle'],
        ]);
        $row->created_at = $when;
        $row->updated_at = $when;
        $row->save();
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(
        ?User $actor,
        string $action,
        string $entityType,
        int $entityId,
        ?array $old,
        ?array $new,
        ?Carbon $when = null,
    ): void {
        $log = AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'full-lifecycle-seeder',
        ]);

        if ($when !== null) {
            $log->created_at = $when;
            $log->updated_at = $when;
            $log->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notify(
        User $citizen,
        NotificationType $type,
        array $data,
        string $eventKey,
        ?Carbon $when = null,
        bool $read = false,
    ): void {
        if (Notification::query()->where('event_key', $eventKey)->exists()) {
            return;
        }

        $replace = [
            'application_number' => (string) ($data['application_number'] ?? ''),
            'license_number' => (string) ($data['license_number'] ?? ''),
        ];

        $notification = Notification::query()->create([
            'user_id' => $citizen->id,
            'title' => __($type->titleKey(), $replace),
            'body' => __($type->bodyKey(), $replace),
            'type' => $type->value,
            'read_at' => $read ? ($when ?? now())->copy()->addHours(2) : null,
            'data' => $data,
            'event_key' => $eventKey,
        ]);

        if ($when !== null) {
            $notification->created_at = $when;
            $notification->updated_at = $when;
            $notification->save();
        }
    }

    private function notifyStatus(LicenseApplication $application, NotificationType $type, Carbon $when): void
    {
        $application->loadMissing('citizen');
        if ($application->citizen === null) {
            return;
        }

        $this->notify(
            $application->citizen,
            $type,
            [
                'application_id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $application->status instanceof ApplicationStatus
                    ? $application->status->value
                    : (string) $application->status,
            ],
            'flow:'.$application->application_number.':'.$type->value,
            $when,
        );
    }

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
            ->orderBy('id')
            ->get();
    }

    private function testType(string $code): TestType
    {
        return TestType::query()->where('code', $code)->where('is_active', true)->firstOrFail();
    }

    private function slotFor(TestType $testType, bool $preferPast): AppointmentSlot
    {
        $cacheKey = $testType->id.'|'.($preferPast ? 'past' : 'future');
        if (isset($this->slotCache[$cacheKey])) {
            return $this->slotCache[$cacheKey];
        }

        $date = $preferPast
            ? now()->subDays(40)->toDateString()
            : now()->addDays(8)->toDateString();

        $slot = AppointmentSlot::query()
            ->where('test_type_id', $testType->id)
            ->whereDate('date', $date)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->first();

        $slot ??= AppointmentSlot::query()
            ->where('test_type_id', $testType->id)
            ->where('is_active', true)
            ->orderBy($preferPast ? 'date' : 'date')
            ->first();

        if ($slot === null) {
            throw new RuntimeException('No appointment slot available for '.$testType->code.'. Run AppointmentSlotsSeeder first.');
        }

        $this->slotCache[$cacheKey] = $slot;

        return $slot;
    }

    private function upsertSlot(
        TestType $testType,
        mixed $centerId,
        string $date,
        string $start,
        string $end,
        int $capacity,
    ): AppointmentSlot {
        return AppointmentSlot::query()->updateOrCreate(
            [
                'test_type_id' => $testType->id,
                'date' => $date,
                'start_time' => $start,
                'end_time' => $end,
            ],
            [
                'appointment_center_id' => $centerId,
                'capacity' => $capacity,
                'location' => 'مركز سرتاك — بيانات دورة الحياة',
                'is_active' => true,
                'deactivated_at' => null,
            ]
        );
    }

    private function resetFlowApplication(string $applicationNumber): void
    {
        $this->assertFlowNumber($applicationNumber);

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
            Fine::query()->where('license_id', $license->id)->update(['license_id' => null]);
            $license->forceDelete();
        }

        TestResult::query()->where('application_id', $application->id)->delete();
        TestAppointment::withTrashed()->where('application_id', $application->id)->forceDelete();
        ApplicationStatusHistory::query()->where('application_id', $application->id)->delete();
        ApplicationDocument::withTrashed()->where('application_id', $application->id)->forceDelete();
        Payment::withTrashed()->where('application_id', $application->id)->forceDelete();
        AuditLog::query()
            ->where('entity_type', 'license_application')
            ->where('entity_id', $application->id)
            ->delete();

        if ($application->trashed()) {
            $application->restore();
        }
    }

    private function assertFlowNumber(string $applicationNumber): void
    {
        if (! str_starts_with($applicationNumber, self::APP_PREFIX)) {
            throw new RuntimeException('Refusing to mutate a non-flow application: '.$applicationNumber);
        }
    }

    private function putDemoFile(LicenseApplication $application, RequiredDocument $document): string
    {
        $path = 'application_documents/'.$application->id.'/flow-'.$document->code.'.pdf';
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
            ."BT /F1 16 Tf 72 720 Td (DLMS lifecycle document) Tj 0 -24 Td ({$title}) Tj ET\n"
            ."endstream endobj\n"
            ."5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
            ."xref\n0 6\n0000000000 65535 f \n"
            ."trailer << /Root 1 0 R /Size 6 >>\nstartxref\n0\n%%EOF\n";
    }

    private function employeeByRole(string $roleName): ?User
    {
        return User::query()
            ->whereIn('user_type', [UserType::Employee, UserType::Admin])
            ->whereHas('role', fn ($q) => $q->where('name', $roleName))
            ->first();
    }

    private function emailFromNumber(string $number): string
    {
        $slug = Str::of($number)->lower()->replace('_', '-')->toString();

        return $slug.'@syrtak.local';
    }

    private function nextName(): string
    {
        $i = $this->citizenSeq++;
        $first = self::FIRST_NAMES[$i % count(self::FIRST_NAMES)];
        $last = self::LAST_NAMES[intdiv($i, 3) % count(self::LAST_NAMES)];

        return $first.' '.$last;
    }

    private function nextPhone(): string
    {
        return '095'.str_pad((string) (1000000 + $this->citizenSeq), 7, '0', STR_PAD_LEFT);
    }

    private function nextNationalId(): string
    {
        return '88'.str_pad((string) (100000000 + $this->citizenSeq), 9, '0', STR_PAD_LEFT);
    }

    private function nextGovernorate(): string
    {
        return self::GOVERNORATES[$this->citizenSeq % count(self::GOVERNORATES)];
    }
}

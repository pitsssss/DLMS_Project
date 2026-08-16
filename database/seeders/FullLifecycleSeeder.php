<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use App\Enums\EmployeeSessionEndedReason;
use App\Enums\LicenseStatus;
use App\Enums\ProfileStatus;
use App\Enums\PushDeliveryStatus;
use App\Enums\UserType;
use App\Models\AppointmentSlot;
use App\Models\ContactMessage;
use App\Models\EmployeeSession;
use App\Models\Notification;
use App\Models\PushDelivery;
use App\Models\PushDevice;
use App\Models\Role;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Enums\AgentMessageRole;
use App\Modules\AIAgent\Enums\AgentSessionStatus;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use Database\Seeders\Support\FullLifecycleKit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a realistic, high-volume dataset covering every dashboard/mobile feature
 * and every business status — each row follows the real DLMS lifecycle.
 *
 *   php artisan db:seed --class=FullLifecycleSeeder
 */
class FullLifecycleSeeder extends Seeder
{
    /** @var list<string> */
    private const LICENSE_TYPES = ['private', 'public', 'truck', 'bus'];

    /** @var list<string> */
    private const FOLLOW_ON_SERVICES = ['renew_license', 'lost_replacement', 'damaged_replacement'];

    public function run(): void
    {
        $kit = new FullLifecycleKit;
        $kit->ensureHighCapacitySlots();

        $this->seedNewLicenseMatrix($kit);
        $this->seedFollowOnServices($kit);
        $this->seedUnblockFlows($kit);
        $this->seedQueueVolume($kit);
        $this->seedLicenseVariants($kit);
        $this->seedProfileCases($kit);
        $this->seedContactMessages();
        $this->seedInactiveEmployees();
        $this->seedEmployeeSessions();
        $this->seedInactiveSlot();
        $this->seedAiAgentSamples();
        $this->seedPushSamples();

        $this->command?->info('Full lifecycle dataset is ready (FLOW-* applications).');
        $this->command?->info('Citizen password: '.FullLifecycleKit::PASSWORD);
    }

    private function seedNewLicenseMatrix(FullLifecycleKit $kit): void
    {
        $statuses = [
            ApplicationStatus::Draft,
            ApplicationStatus::DocumentsUnderReview,
            ApplicationStatus::DocumentsRejected,
            ApplicationStatus::PaymentPending,
            ApplicationStatus::PaymentCompleted,
            ApplicationStatus::AppointmentPending,
            ApplicationStatus::InTesting,
            ApplicationStatus::WaitingRetest,
            ApplicationStatus::Approved,
            ApplicationStatus::LicenseIssued,
            ApplicationStatus::Rejected,
            ApplicationStatus::Cancelled,
            ApplicationStatus::AdministrativeReview,
        ];

        foreach (self::LICENSE_TYPES as $type) {
            foreach ($statuses as $status) {
                $kit->seedScenario([
                    'application_number' => $this->number('NL', $type, $status->value, 1),
                    'service_code' => 'new_license',
                    'license_type_code' => $type,
                    'status' => $status,
                    'submitted_days_ago' => 8 + (crc32($type.$status->value) % 40),
                    'testing_stage' => match ($type) {
                        'public' => 'theory',
                        'truck' => 'practical',
                        default => 'vision',
                    },
                    'payment_variant' => $status === ApplicationStatus::PaymentPending ? 'pending' : 'completed',
                    'document_variant' => match ($status) {
                        ApplicationStatus::Draft => 'partial',
                        ApplicationStatus::DocumentsUnderReview => 'all_pending',
                        ApplicationStatus::DocumentsRejected => 'rejected',
                        default => 'all_approved',
                    },
                    'print' => $status === ApplicationStatus::LicenseIssued && $type === 'private',
                    'paid_fine' => $status === ApplicationStatus::LicenseIssued && $type === 'public',
                    'cancelled_fine' => $status === ApplicationStatus::LicenseIssued && $type === 'bus',
                ]);
            }
        }
    }

    private function seedFollowOnServices(FullLifecycleKit $kit): void
    {
        $statuses = [
            ApplicationStatus::Draft,
            ApplicationStatus::DocumentsUnderReview,
            ApplicationStatus::DocumentsRejected,
            ApplicationStatus::PaymentPending,
            ApplicationStatus::Approved,
            ApplicationStatus::LicenseIssued,
            ApplicationStatus::Cancelled,
            ApplicationStatus::Rejected,
        ];

        $copy = 0;
        foreach (self::FOLLOW_ON_SERVICES as $service) {
            foreach ($statuses as $status) {
                foreach (['private', 'public'] as $type) {
                    $copy++;
                    $kit->seedScenario([
                        'application_number' => $this->number($this->serviceAbbrev($service), $type, $status->value, $copy),
                        'service_code' => $service,
                        'license_type_code' => $type,
                        'status' => $status,
                        'submitted_days_ago' => 5 + ($copy % 25),
                        'payment_variant' => $status === ApplicationStatus::PaymentPending ? 'under_verification' : 'completed',
                        'document_variant' => match ($status) {
                            ApplicationStatus::Draft => 'partial',
                            ApplicationStatus::DocumentsUnderReview => 'mixed',
                            ApplicationStatus::DocumentsRejected => 'rejected',
                            default => 'all_approved',
                        },
                    ]);
                }
            }
        }
    }

    private function seedUnblockFlows(FullLifecycleKit $kit): void
    {
        $statuses = [
            ApplicationStatus::DocumentsUnderReview,
            ApplicationStatus::PaymentPending,
            ApplicationStatus::Approved,
            ApplicationStatus::LicenseIssued,
            ApplicationStatus::Rejected,
            ApplicationStatus::Cancelled,
        ];

        foreach ($statuses as $i => $status) {
            foreach (['private', 'truck'] as $n => $type) {
                $kit->seedScenario([
                    'application_number' => $this->number('UB', $type, $status->value, $i + 1),
                    'service_code' => 'license_unblock',
                    'license_type_code' => $type,
                    'status' => $status,
                    'submitted_days_ago' => 6 + $i,
                    'payment_variant' => $status === ApplicationStatus::PaymentPending ? 'pending' : 'completed',
                    'unpaid_fine' => $status === ApplicationStatus::Approved && $n === 0,
                ]);
            }
        }
    }

    private function seedQueueVolume(FullLifecycleKit $kit): void
    {
        $queues = [
            [ApplicationStatus::DocumentsUnderReview, 10, ['late' => false, 'document_variant' => 'all_pending']],
            [ApplicationStatus::DocumentsUnderReview, 4, ['late' => true, 'document_variant' => 'all_pending', 'submitted_days_ago' => 9]],
            [ApplicationStatus::DocumentsRejected, 5, ['document_variant' => 'rejected']],
            [ApplicationStatus::PaymentPending, 8, ['payment_variant' => 'pending']],
            [ApplicationStatus::PaymentPending, 3, ['payment_variant' => 'failed']],
            [ApplicationStatus::PaymentPending, 3, ['payment_variant' => 'under_verification']],
            [ApplicationStatus::AppointmentPending, 6, []],
            [ApplicationStatus::InTesting, 5, ['testing_stage' => 'vision', 'prior_cancelled_appointment' => true]],
            [ApplicationStatus::InTesting, 4, ['testing_stage' => 'theory']],
            [ApplicationStatus::InTesting, 4, ['testing_stage' => 'practical']],
            [ApplicationStatus::WaitingRetest, 4, ['retest_result' => 'failed']],
            [ApplicationStatus::WaitingRetest, 3, ['retest_result' => 'no_show']],
            [ApplicationStatus::Approved, 8, []],
            [ApplicationStatus::LicenseIssued, 10, ['print' => true, 'paid_fine' => true]],
            [ApplicationStatus::Draft, 5, ['document_variant' => 'none']],
            [ApplicationStatus::Cancelled, 4, []],
            [ApplicationStatus::Rejected, 4, []],
            [ApplicationStatus::AdministrativeReview, 3, []],
        ];

        $seq = 0;
        foreach ($queues as [$status, $count, $extra]) {
            for ($i = 1; $i <= $count; $i++) {
                $seq++;
                $type = self::LICENSE_TYPES[$seq % count(self::LICENSE_TYPES)];
                $kit->seedScenario(array_merge([
                    'application_number' => $this->number('QV', $type, $status->value, $seq),
                    'service_code' => 'new_license',
                    'license_type_code' => $type,
                    'status' => $status,
                    'submitted_days_ago' => 3 + ($seq % 50),
                ], $extra));
            }
        }
    }

    private function seedLicenseVariants(FullLifecycleKit $kit): void
    {
        $kit->seedScenario([
            'application_number' => 'FLOW-LIC-EXPIRED-01',
            'service_code' => 'new_license',
            'license_type_code' => 'private',
            'status' => ApplicationStatus::LicenseIssued,
            'expire' => true,
            'submitted_days_ago' => 80,
        ]);

        $kit->seedScenario([
            'application_number' => 'FLOW-LIC-BLOCKED-01',
            'service_code' => 'new_license',
            'license_type_code' => 'public',
            'status' => ApplicationStatus::LicenseIssued,
            'block' => true,
            'unpaid_fine' => true,
            'submitted_days_ago' => 40,
        ]);

        $kit->seedScenario([
            'application_number' => 'FLOW-LIC-BLOCKED-READY-01',
            'service_code' => 'new_license',
            'license_type_code' => 'truck',
            'status' => ApplicationStatus::LicenseIssued,
            'block' => true,
            'submitted_days_ago' => 35,
        ]);

        $kit->seedScenario([
            'application_number' => 'FLOW-LIC-SUSPENDED-01',
            'service_code' => 'new_license',
            'license_type_code' => 'bus',
            'status' => ApplicationStatus::LicenseIssued,
            'suspend' => true,
            'submitted_days_ago' => 28,
        ]);

        $kit->seedScenario([
            'application_number' => 'FLOW-LIC-PRINTED-01',
            'service_code' => 'new_license',
            'license_type_code' => 'private',
            'status' => ApplicationStatus::LicenseIssued,
            'print' => true,
            'paid_fine' => true,
            'cancelled_fine' => true,
            'submitted_days_ago' => 20,
        ]);
    }

    private function seedProfileCases(FullLifecycleKit $kit): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $kit->seedProfileCitizen('pending-'.$i, ProfileStatus::PendingReview);
        }
        for ($i = 1; $i <= 4; $i++) {
            $kit->seedProfileCitizen('rejected-'.$i, ProfileStatus::Rejected);
        }
        for ($i = 1; $i <= 4; $i++) {
            $kit->seedProfileCitizen('incomplete-'.$i, ProfileStatus::Incomplete);
        }
        for ($i = 1; $i <= 3; $i++) {
            $kit->seedDeactivatedCitizen('deactivated-'.$i);
        }
    }

    private function seedInactiveEmployees(): void
    {
        $role = Role::query()->where('name', 'employee')->first()
            ?? Role::query()->where('name', 'application_manager')->firstOrFail();

        User::query()->updateOrCreate(
            ['email' => 'flow.inactive.employee@syrtak.gov.sy'],
            [
                'name' => 'موظف موقف عن العمل',
                'phone' => '0988000099',
                'password' => FullLifecycleKit::PASSWORD,
                'role_id' => $role->id,
                'user_type' => UserType::Employee,
                'profile_completed' => true,
                'profile_status' => ProfileStatus::Approved,
                'is_active' => false,
                'deactivated_at' => now()->subDays(12),
                'deactivation_reason' => 'إيقاف مؤقت بطلب إداري.',
                'email_verified_at' => now()->subMonths(6),
                'phone_verified_at' => now()->subMonths(6),
            ]
        );
    }

    private function seedContactMessages(): void
    {
        $statuses = ['new', 'read', 'in_progress', 'resolved', 'closed'];
        $subjects = [
            'استفسار عن موعد اختبار النظر',
            'مشكلة في رفع الوثيقة الطبية',
            'طلب توضيح رسوم التجديد',
            'لم تصلني رسالة رمز التحقق',
            'استفسار عن غرامة مسجلة بالخطأ',
        ];

        $citizens = User::query()
            ->where('user_type', UserType::Citizen)
            ->where('email', 'like', 'flow-%@syrtak.local')
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($statuses as $i => $status) {
            for ($n = 1; $n <= 4; $n++) {
                $citizen = $citizens[($i * 4 + $n) % max($citizens->count(), 1)] ?? null;
                ContactMessage::query()->updateOrCreate(
                    [
                        'email' => $citizen?->email ?? 'flow.contact.'.$status.'.'.$n.'@syrtak.local',
                        'subject' => $subjects[($i + $n) % count($subjects)].' #'.$n,
                    ],
                    [
                        'user_id' => $citizen?->id,
                        'name' => $citizen?->name ?? 'مواطن زائر',
                        'phone' => $citizen?->phone,
                        'message' => 'أرجو التكرم بمراجعة حالتي وتزويدي بالإجراء المطلوب في أقرب وقت. رقم الطلب إن وجد مذكور في الملف الشخصي.',
                        'status' => $status,
                        'created_at' => now()->subDays(($i * 4) + $n),
                    ]
                );
            }
        }
    }

    private function seedEmployeeSessions(): void
    {
        $employees = User::query()
            ->whereIn('user_type', [UserType::Employee, UserType::Admin])
            ->orderBy('id')
            ->limit(8)
            ->get();

        $admin = User::query()->where('user_type', UserType::Admin)->first();

        foreach ($employees as $i => $employee) {
            EmployeeSession::query()->updateOrCreate(
                ['session_uuid' => '11111111-1111-4111-a111-'.str_pad((string) ($i + 1), 12, '0', STR_PAD_LEFT)],
                [
                    'user_id' => $employee->id,
                    'auth_driver' => 'sanctum',
                    'logged_in_at' => now()->subDays(2)->subHours($i),
                    'last_seen_at' => now()->subDays(2),
                    'logged_out_at' => now()->subDays(1),
                    'ended_reason' => EmployeeSessionEndedReason::ExplicitLogout->value,
                    'initial_ip_address' => '10.0.0.'.(20 + $i),
                    'last_ip_address' => '10.0.0.'.(20 + $i),
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) FullLifecycleSeeder',
                    'device_type' => 'desktop',
                    'operating_system' => 'Windows',
                    'browser' => 'Chrome',
                    'browser_version' => '126',
                ]
            );
        }

        if ($employees->isNotEmpty() && $admin !== null) {
            EmployeeSession::query()->updateOrCreate(
                ['session_uuid' => '22222222-2222-4222-a222-000000000001'],
                [
                    'user_id' => $employees->first()->id,
                    'auth_driver' => 'sanctum',
                    'logged_in_at' => now()->subDays(5),
                    'last_seen_at' => now()->subDays(4),
                    'revoked_at' => now()->subDays(3),
                    'revoked_by' => $admin->id,
                    'revoke_reason' => 'إنهاء الجلسة من مدير النظام لأغراض المراجعة.',
                    'ended_reason' => EmployeeSessionEndedReason::Revoked->value,
                    'initial_ip_address' => '10.0.1.8',
                    'last_ip_address' => '10.0.1.8',
                    'device_type' => 'desktop',
                    'operating_system' => 'Windows',
                    'browser' => 'Edge',
                    'browser_version' => '125',
                ]
            );

            EmployeeSession::query()->updateOrCreate(
                ['session_uuid' => '33333333-3333-4333-a333-000000000001'],
                [
                    'user_id' => $employees->last()->id,
                    'auth_driver' => 'sanctum',
                    'logged_in_at' => now()->subDays(10),
                    'last_seen_at' => now()->subDays(9),
                    'expires_at' => now()->subDays(8),
                    'ended_reason' => EmployeeSessionEndedReason::Expired->value,
                    'initial_ip_address' => '10.0.2.4',
                    'last_ip_address' => '10.0.2.4',
                    'device_type' => 'mobile',
                    'operating_system' => 'Android',
                    'browser' => 'Chrome',
                    'browser_version' => '124',
                ]
            );
        }
    }

    private function seedInactiveSlot(): void
    {
        $slot = AppointmentSlot::query()
            ->where('is_active', true)
            ->where('capacity', 500)
            ->orderByDesc('id')
            ->first();

        if ($slot === null) {
            return;
        }

        AppointmentSlot::query()->updateOrCreate(
            [
                'test_type_id' => $slot->test_type_id,
                'date' => now()->addDays(20)->toDateString(),
                'start_time' => '18:00:00',
                'end_time' => '19:00:00',
            ],
            [
                'appointment_center_id' => $slot->appointment_center_id,
                'capacity' => 10,
                'booked_count' => 0,
                'location' => 'فترة مسائية موقوفة — بيانات تجريبية',
                'is_active' => false,
                'deactivated_at' => now()->subDay(),
            ]
        );
    }

    private function seedAiAgentSamples(): void
    {
        $citizens = User::query()
            ->where('user_type', UserType::Citizen)
            ->where('email', 'like', 'flow-%@syrtak.local')
            ->orderBy('id')
            ->limit(6)
            ->get();

        $actionStatuses = [
            AgentActionStatus::AwaitingConfirmation,
            AgentActionStatus::Executed,
            AgentActionStatus::Cancelled,
            AgentActionStatus::Failed,
        ];

        foreach ($citizens as $i => $citizen) {
            $session = AIAgentSession::query()->updateOrCreate(
                [
                    'user_id' => $citizen->id,
                    'current_intent' => $i % 2 === 0 ? 'create_new_license_application' : 'get_application_status',
                ],
                [
                    'status' => $i === 0 ? AgentSessionStatus::Closed : AgentSessionStatus::Active,
                    'context' => ['source' => 'full_lifecycle_seeder'],
                    'last_message_at' => now()->subHours($i + 1),
                ]
            );

            AIAgentMessage::query()->updateOrCreate(
                [
                    'session_id' => $session->id,
                    'role' => AgentMessageRole::User,
                    'content' => 'بدي رخصة قيادة جديدة خاصة',
                ],
                ['metadata' => ['seeder' => true]]
            );
            AIAgentMessage::query()->updateOrCreate(
                [
                    'session_id' => $session->id,
                    'role' => AgentMessageRole::Assistant,
                    'content' => 'حسناً، يمكنني مساعدتك بإنشاء طلب رخصة خاصة. هل تريد المتابعة؟',
                ],
                ['metadata' => ['seeder' => true]]
            );

            $actionStatus = $actionStatuses[$i % count($actionStatuses)];
            AIAgentAction::query()->updateOrCreate(
                [
                    'session_id' => $session->id,
                    'user_id' => $citizen->id,
                    'action_name' => 'create_application',
                ],
                [
                    'arguments' => ['license_type' => 'private', 'service_type' => 'new_license'],
                    'status' => $actionStatus,
                    'requires_confirmation' => true,
                    'confirmation_message' => 'تأكيد إنشاء طلب رخصة خاصة جديدة؟',
                    'result' => $actionStatus === AgentActionStatus::Executed
                        ? ['ok' => true]
                        : null,
                    'error_message' => $actionStatus === AgentActionStatus::Failed
                        ? 'تعذر تنفيذ الإجراء لأن الملف الشخصي غير مكتمل.'
                        : null,
                    'confirmed_at' => in_array($actionStatus, [AgentActionStatus::Executed, AgentActionStatus::Failed], true)
                        ? now()->subHours($i)
                        : null,
                    'executed_at' => $actionStatus === AgentActionStatus::Executed ? now()->subHours($i) : null,
                ]
            );
        }
    }

    private function seedPushSamples(): void
    {
        $citizens = User::query()
            ->where('user_type', UserType::Citizen)
            ->where('email', 'like', 'flow-%@syrtak.local')
            ->orderBy('id')
            ->limit(5)
            ->get();

        $deliveryStatuses = [
            PushDeliveryStatus::Sent,
            PushDeliveryStatus::Failed,
            PushDeliveryStatus::Pending,
            PushDeliveryStatus::InvalidToken,
        ];

        foreach ($citizens as $i => $citizen) {
            $device = PushDevice::query()->updateOrCreate(
                [
                    'user_id' => $citizen->id,
                    'device_id' => 'flow-device-'.$citizen->id,
                ],
                [
                    'platform' => $i % 2 === 0 ? 'android' : 'ios',
                    'token' => 'flow-push-token-'.$citizen->id.'-'.Str::lower(Str::random(8)),
                    'token_hash' => hash('sha256', 'flow-device-'.$citizen->id),
                    'last_registered_at' => now()->subDays($i + 1),
                ]
            );

            $notification = Notification::query()
                ->where('user_id', $citizen->id)
                ->orderByDesc('id')
                ->first();

            if ($notification === null) {
                continue;
            }

            $status = $deliveryStatuses[$i % count($deliveryStatuses)];
            PushDelivery::query()->updateOrCreate(
                ['delivery_key' => PushDelivery::deliveryKey($notification->id, $device->id)],
                [
                    'notification_id' => $notification->id,
                    'push_device_id' => $device->id,
                    'status' => $status,
                    'attempts' => $status === PushDeliveryStatus::Pending ? 0 : 1,
                    'provider_message_id' => $status === PushDeliveryStatus::Sent ? 'fcm-'.$notification->id : null,
                    'last_error_category' => $status === PushDeliveryStatus::Failed ? 'provider_unavailable' : null,
                    'last_http_status' => $status === PushDeliveryStatus::Sent ? 200 : ($status === PushDeliveryStatus::Failed ? 503 : null),
                    'last_attempt_at' => $status === PushDeliveryStatus::Pending ? null : now()->subHours($i + 1),
                    'sent_at' => $status === PushDeliveryStatus::Sent ? now()->subHours($i + 1) : null,
                    'failed_at' => in_array($status, [PushDeliveryStatus::Failed, PushDeliveryStatus::InvalidToken], true)
                        ? now()->subHours($i + 1)
                        : null,
                ]
            );
        }
    }

    private function number(string $service, string $licenseType, string $status, int $copy): string
    {
        $type = match ($licenseType) {
            'private' => 'PRV',
            'public' => 'PUB',
            'truck' => 'TRK',
            'bus' => 'BUS',
            default => strtoupper(substr($licenseType, 0, 3)),
        };

        $st = match ($status) {
            'draft' => 'DRF',
            'documents_under_review' => 'DUR',
            'documents_rejected' => 'DRJ',
            'payment_pending' => 'PPD',
            'payment_completed' => 'PCD',
            'appointment_pending' => 'APT',
            'in_testing' => 'TST',
            'waiting_retest' => 'WRT',
            'approved' => 'APP',
            'license_issued' => 'ISS',
            'rejected' => 'REJ',
            'cancelled' => 'CAN',
            'administrative_review' => 'ADM',
            default => strtoupper(substr($status, 0, 3)),
        };

        return sprintf('FLOW-%s-%s-%s-%02d', $service, $type, $st, $copy);
    }

    private function serviceAbbrev(string $service): string
    {
        return match ($service) {
            'renew_license' => 'RN',
            'lost_replacement' => 'LS',
            'damaged_replacement' => 'DM',
            'license_unblock' => 'UB',
            default => 'NL',
        };
    }
}

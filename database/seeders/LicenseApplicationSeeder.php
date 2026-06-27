<?php

namespace Database\Seeders;

use App\Enums\ApplicationStatus;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LicenseApplicationSeeder extends Seeder
{
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        DB::table('audit_logs')->truncate();
        DB::table('license_applications')->truncate();
        DB::table('license_types')->truncate();
        DB::table('service_types')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $now = Carbon::parse('2026-05-14 14:43:18');

        $licenseTypes = [
            ['id' => 1, 'name' => 'Private License', 'code' => 'private', 'minimum_age' => 18, 'validity_years' => 5],
            ['id' => 2, 'name' => 'Public License', 'code' => 'public', 'minimum_age' => 21, 'validity_years' => 5],
            ['id' => 3, 'name' => 'Truck License', 'code' => 'truck', 'minimum_age' => 21, 'validity_years' => 5],
            ['id' => 4, 'name' => 'Bus License', 'code' => 'bus', 'minimum_age' => 21, 'validity_years' => 5],
        ];

        foreach ($licenseTypes as $type) {
            DB::table('license_types')->insert([
                'id' => $type['id'],
                'name' => $type['name'],
                'code' => $type['code'],
                'minimum_age' => $type['minimum_age'],
                'validity_years' => $type['validity_years'],
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $serviceTypes = [
            ['id' => 1, 'name' => 'New License', 'code' => 'new_license'],
            ['id' => 2, 'name' => 'Renew License', 'code' => 'renew_license'],
            ['id' => 3, 'name' => 'Lost Replacement', 'code' => 'lost_replacement'],
            ['id' => 4, 'name' => 'Damaged Replacement', 'code' => 'damaged_replacement'],
            ['id' => 5, 'name' => 'License Unblock', 'code' => 'license_unblock'],
        ];

        foreach ($serviceTypes as $service) {
            DB::table('service_types')->insert([
                'id' => $service['id'],
                'name' => $service['name'],
                'code' => $service['code'],
                'description' => null,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $citizenIds = DB::table('users')
            ->where('user_type', 'citizen')
            ->pluck('id')
            ->values()
            ->toArray();

        if (empty($citizenIds)) {
            throw new RuntimeException('No citizen users were found. License applications require users with user_type = citizen.');
        }

        $employeeIds = DB::table('users')
            ->whereIn('user_type', ['admin', 'employee'])
            ->pluck('id')
            ->values()
            ->toArray();

        $testTypeIds = DB::table('test_types')
            ->whereIn('code', ['vision', 'theory', 'practical'])
            ->pluck('id', 'code')
            ->toArray();

        $missingTestTypes = array_diff(['vision', 'theory', 'practical'], array_keys($testTypeIds));

        if (! empty($missingTestTypes)) {
            throw new RuntimeException('Missing required test types: ' . implode(', ', $missingTestTypes));
        }

        $finalStatuses = [
            ApplicationStatus::Draft->value,
            ApplicationStatus::DocumentsUnderReview->value,
            ApplicationStatus::DocumentsRejected->value,
            ApplicationStatus::PaymentPending->value,
            ApplicationStatus::PaymentCompleted->value,
            ApplicationStatus::Approved->value,
            ApplicationStatus::AppointmentPending->value,
            ApplicationStatus::InTesting->value,
            ApplicationStatus::WaitingRetest->value,
            ApplicationStatus::LicenseIssued->value,
            ApplicationStatus::Rejected->value,
            ApplicationStatus::Cancelled->value,
            ApplicationStatus::AdministrativeReview->value,
        ];

        $rejectionReasons = [
            'Invalid documentation provided',
            'Missing required identity document',
            'Uploaded documents are not clear',
            'Applicant does not meet the requirements',
            'Information mismatch found during review',
        ];

        for ($i = 1; $i <= 100; $i++) {
            $finalStatus = $finalStatuses[($i - 1) % count($finalStatuses)];
            $createdAt = Carbon::parse('2026-05-01 09:00:00')->addHours($i * 3);
            $applicationNumber = 'APP-2026-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $citizenId = $citizenIds[($i - 1) % count($citizenIds)];
            $licenseTypeId = $licenseTypes[($i - 1) % count($licenseTypes)]['id'];
            $serviceTypeId = $serviceTypes[($i - 1) % count($serviceTypes)]['id'];
            $rejectionReason = $rejectionReasons[($i - 1) % count($rejectionReasons)];

            $lifecycle = $this->buildLifecycle(
                $finalStatus,
                $applicationNumber,
                $citizenId,
                $licenseTypeId,
                $serviceTypeId,
                $createdAt,
                $testTypeIds,
                $employeeIds,
                $rejectionReason
            );

            $applicationId = DB::table('license_applications')->insertGetId($lifecycle['application']);

            foreach ($lifecycle['audit_logs'] as $auditLog) {
                $this->insertAuditLog(
                    $auditLog['user_id'],
                    $auditLog['action'],
                    $applicationId,
                    $auditLog['old_values'],
                    $auditLog['new_values'],
                    $auditLog['created_at']
                );
            }
        }
    }

    private function buildLifecycle(
        string $finalStatus,
        string $applicationNumber,
        int $citizenId,
        int $licenseTypeId,
        int $serviceTypeId,
        Carbon $createdAt,
        array $testTypeIds,
        array $employeeIds,
        string $rejectionReason
    ): array {
        $application = [
            'application_number' => $applicationNumber,
            'citizen_id' => $citizenId,
            'license_type_id' => $licenseTypeId,
            'service_type_id' => $serviceTypeId,
            'status' => ApplicationStatus::Draft->value,
            'current_test_type_id' => null,
            'rejection_reason' => null,
            'submitted_at' => null,
            'approved_at' => null,
            'issued_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];

        $auditLogs = [];

        $this->pushAuditLog(
            $auditLogs,
            $citizenId,
            'created',
            null,
            [
                'application_number' => $applicationNumber,
                'status' => ApplicationStatus::Draft->value,
            ],
            $createdAt
        );

        if ($finalStatus === ApplicationStatus::Draft->value) {
            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        $submittedAt = $createdAt->copy()->addHours(2);

        $this->pushAuditLog(
            $auditLogs,
            $citizenId,
            'status_changed',
            [
                'status' => ApplicationStatus::Draft->value,
                'submitted_at' => null,
            ],
            [
                'status' => ApplicationStatus::DocumentsUnderReview->value,
                'submitted_at' => $submittedAt->format('Y-m-d H:i:s'),
            ],
            $submittedAt
        );

        $application['status'] = ApplicationStatus::DocumentsUnderReview->value;
        $application['submitted_at'] = $submittedAt;
        $application['updated_at'] = $submittedAt;

        if ($finalStatus === ApplicationStatus::DocumentsUnderReview->value) {
            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        $reviewTime = $submittedAt->copy()->addHours(4);

        if ($finalStatus === ApplicationStatus::DocumentsRejected->value) {
            $this->pushAuditLog(
                $auditLogs,
                $this->actorIdFromPool($employeeIds),
                'status_changed',
                [
                    'status' => ApplicationStatus::DocumentsUnderReview->value,
                    'rejection_reason' => null,
                ],
                [
                    'status' => ApplicationStatus::DocumentsRejected->value,
                    'rejection_reason' => $rejectionReason,
                ],
                $reviewTime
            );

            $application['status'] = ApplicationStatus::DocumentsRejected->value;
            $application['rejection_reason'] = $rejectionReason;
            $application['updated_at'] = $reviewTime;

            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        if ($finalStatus === ApplicationStatus::Rejected->value) {
            $this->pushAuditLog(
                $auditLogs,
                $this->actorIdFromPool($employeeIds),
                'status_changed',
                [
                    'status' => ApplicationStatus::DocumentsUnderReview->value,
                    'rejection_reason' => null,
                ],
                [
                    'status' => ApplicationStatus::Rejected->value,
                    'rejection_reason' => $rejectionReason,
                ],
                $reviewTime
            );

            $application['status'] = ApplicationStatus::Rejected->value;
            $application['rejection_reason'] = $rejectionReason;
            $application['updated_at'] = $reviewTime;

            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        if ($finalStatus === ApplicationStatus::Cancelled->value) {
            $this->pushAuditLog(
                $auditLogs,
                $citizenId,
                'status_changed',
                [
                    'status' => ApplicationStatus::DocumentsUnderReview->value,
                ],
                [
                    'status' => ApplicationStatus::Cancelled->value,
                ],
                $reviewTime
            );

            $application['status'] = ApplicationStatus::Cancelled->value;
            $application['updated_at'] = $reviewTime;

            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        if ($finalStatus === ApplicationStatus::AdministrativeReview->value) {
            $this->pushAuditLog(
                $auditLogs,
                $this->actorIdFromPool($employeeIds),
                'status_changed',
                [
                    'status' => ApplicationStatus::DocumentsUnderReview->value,
                ],
                [
                    'status' => ApplicationStatus::AdministrativeReview->value,
                ],
                $reviewTime
            );

            $application['status'] = ApplicationStatus::AdministrativeReview->value;
            $application['updated_at'] = $reviewTime;

            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        $paymentPendingAt = $reviewTime->copy()->addHours(1);

        $this->pushAuditLog(
            $auditLogs,
            null,
            'status_changed',
            [
                'status' => ApplicationStatus::DocumentsUnderReview->value,
            ],
            [
                'status' => ApplicationStatus::PaymentPending->value,
            ],
            $paymentPendingAt
        );

        $application['status'] = ApplicationStatus::PaymentPending->value;
        $application['updated_at'] = $paymentPendingAt;

        if ($finalStatus === ApplicationStatus::PaymentPending->value) {
            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        $paymentCompletedAt = $paymentPendingAt->copy()->addHours(3);

        $this->pushAuditLog(
            $auditLogs,
            $citizenId,
            'status_changed',
            [
                'status' => ApplicationStatus::PaymentPending->value,
            ],
            [
                'status' => ApplicationStatus::PaymentCompleted->value,
            ],
            $paymentCompletedAt
        );

        $application['status'] = ApplicationStatus::PaymentCompleted->value;
        $application['updated_at'] = $paymentCompletedAt;

        if ($finalStatus === ApplicationStatus::PaymentCompleted->value) {
            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        $approvedAt = $paymentCompletedAt->copy()->addHours(5);

        $this->pushAuditLog(
            $auditLogs,
            $this->actorIdFromPool($employeeIds),
            'approved',
            [
                'status' => ApplicationStatus::PaymentCompleted->value,
                'approved_at' => null,
            ],
            [
                'status' => ApplicationStatus::Approved->value,
                'approved_at' => $approvedAt->format('Y-m-d H:i:s'),
            ],
            $approvedAt
        );

        $application['status'] = ApplicationStatus::Approved->value;
        $application['approved_at'] = $approvedAt;
        $application['updated_at'] = $approvedAt;

        if ($finalStatus === ApplicationStatus::Approved->value) {
            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        $appointmentPendingAt = $approvedAt->copy()->addHours(2);

        $this->pushAuditLog(
            $auditLogs,
            null,
            'status_changed',
            [
                'status' => ApplicationStatus::Approved->value,
            ],
            [
                'status' => ApplicationStatus::AppointmentPending->value,
            ],
            $appointmentPendingAt
        );

        $application['status'] = ApplicationStatus::AppointmentPending->value;
        $application['updated_at'] = $appointmentPendingAt;

        if ($finalStatus === ApplicationStatus::AppointmentPending->value) {
            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        $firstTestTypeId = $this->testTypeId($testTypeIds, 'vision');
        $testingAt = $appointmentPendingAt->copy()->addHours(4);

        $this->pushAuditLog(
            $auditLogs,
            null,
            'status_changed',
            [
                'status' => ApplicationStatus::AppointmentPending->value,
                'current_test_type_id' => null,
            ],
            [
                'status' => ApplicationStatus::InTesting->value,
                'current_test_type_id' => $firstTestTypeId,
            ],
            $testingAt
        );

        $application['status'] = ApplicationStatus::InTesting->value;
        $application['current_test_type_id'] = $firstTestTypeId;
        $application['updated_at'] = $testingAt;

        if ($finalStatus === ApplicationStatus::InTesting->value) {
            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        if ($finalStatus === ApplicationStatus::WaitingRetest->value) {
            $waitingRetestAt = $testingAt->copy()->addHours(4);

            $this->pushAuditLog(
                $auditLogs,
                $this->actorIdFromPool($employeeIds),
                'status_changed',
                [
                    'status' => ApplicationStatus::InTesting->value,
                ],
                [
                    'status' => ApplicationStatus::WaitingRetest->value,
                ],
                $waitingRetestAt
            );

            $application['status'] = ApplicationStatus::WaitingRetest->value;
            $application['updated_at'] = $waitingRetestAt;

            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        if ($finalStatus === ApplicationStatus::LicenseIssued->value) {
            $theoryTestTypeId = $this->testTypeId($testTypeIds, 'theory');
            $practicalTestTypeId = $this->testTypeId($testTypeIds, 'practical');

            $theoryAt = $testingAt->copy()->addHours(6);

            $this->pushAuditLog(
                $auditLogs,
                $this->actorIdFromPool($employeeIds),
                'test_progress_updated',
                [
                    'current_test_type_id' => $firstTestTypeId,
                ],
                [
                    'current_test_type_id' => $theoryTestTypeId,
                ],
                $theoryAt
            );

            $application['current_test_type_id'] = $theoryTestTypeId;
            $application['updated_at'] = $theoryAt;

            $practicalAt = $theoryAt->copy()->addHours(8);

            $this->pushAuditLog(
                $auditLogs,
                $this->actorIdFromPool($employeeIds),
                'test_progress_updated',
                [
                    'current_test_type_id' => $theoryTestTypeId,
                ],
                [
                    'current_test_type_id' => $practicalTestTypeId,
                ],
                $practicalAt
            );

            $application['current_test_type_id'] = $practicalTestTypeId;
            $application['updated_at'] = $practicalAt;

            $issuedAt = $practicalAt->copy()->addHours(6);

            $this->pushAuditLog(
                $auditLogs,
                $this->actorIdFromPool($employeeIds),
                'status_changed',
                [
                    'status' => ApplicationStatus::InTesting->value,
                    'current_test_type_id' => $practicalTestTypeId,
                    'issued_at' => null,
                ],
                [
                    'status' => ApplicationStatus::LicenseIssued->value,
                    'current_test_type_id' => null,
                    'issued_at' => $issuedAt->format('Y-m-d H:i:s'),
                ],
                $issuedAt
            );

            $application['status'] = ApplicationStatus::LicenseIssued->value;
            $application['current_test_type_id'] = null;
            $application['issued_at'] = $issuedAt;
            $application['updated_at'] = $issuedAt;

            return [
                'application' => $application,
                'audit_logs' => $auditLogs,
            ];
        }

        return [
            'application' => $application,
            'audit_logs' => $auditLogs,
        ];
    }

    private function pushAuditLog(array &$auditLogs, ?int $userId, string $action, ?array $oldValues, ?array $newValues, Carbon $createdAt): void
    {
        $auditLogs[] = [
            'user_id' => $userId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'created_at' => $createdAt,
        ];
    }

    private function insertAuditLog(?int $userId, string $action, int $applicationId, ?array $oldValues, ?array $newValues, Carbon $createdAt): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => 'license_application',
            'entity_id' => $applicationId,
            'old_values' => $oldValues === null ? null : json_encode($oldValues),
            'new_values' => $newValues === null ? null : json_encode($newValues),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'license-application-seeder',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function testTypeId(array $testTypeIds, string $code): int
    {
        if (! isset($testTypeIds[$code])) {
            throw new RuntimeException('Missing required test type: ' . $code);
        }

        return (int) $testTypeIds[$code];
    }

    private function actorIdFromPool(array $userIds): ?int
    {
        if (empty($userIds)) {
            return null;
        }

        return $userIds[array_rand($userIds)];
    }
}

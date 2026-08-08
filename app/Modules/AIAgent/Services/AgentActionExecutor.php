<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\AppointmentStatus;
use App\Exceptions\ApiException;
use App\Models\LicenseType;
use App\Models\ServiceType;
use App\Models\TestAppointment;
use App\Models\TestType;
use App\Models\User;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Support\AgentSafetyRules;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\Payments\Services\ApplicationPaymentService;
use App\Modules\Applications\Resources\ApplicationResource;
use App\Modules\Applications\Services\ApplicationDocumentService;
use App\Modules\Applications\Services\ApplicationService;
use App\Modules\Appointments\Resources\AppointmentSlotResource;
use App\Modules\Appointments\Services\AppointmentService;
use App\Modules\Appointments\Services\TestProgressionService;
use App\Modules\Auth\Services\ProfileService;
use App\Modules\Fines\Resources\FineResource;
use App\Modules\Fines\Services\FineService;
use App\Modules\Licenses\Resources\LicenseResource;
use App\Modules\Licenses\Services\LicenseService;
use App\Modules\Tests\Resources\TestResultResource;
use App\Modules\Tests\Services\TestResultService;

class AgentActionExecutor
{
    /**
     * Keep this list in sync with the switch in execute().
     * Used by Phase 1 tests to ensure every allowed proposed action is actually executable.
     *
     * @var list<string>
     */
    public const SUPPORTED_ACTION_NAMES = [
        'create_application',
        'get_application_status',
        'get_application_next_step',
        'get_required_documents',
        'get_application_fee',
        'get_profile_status',
        'get_fines',
        'get_licenses',
        'get_available_tests',
        'get_appointment_slots',
        'get_current_appointments',
        'book_appointment',
        'reschedule_appointment',
        'cancel_appointment',
        'get_test_results',
        'start_payment',
        'submit_documents_for_review',
    ];

    public function __construct(
        private readonly ApplicationService $applications,
        private readonly ApplicationDocumentService $documents,
        private readonly FineService $fines,
        private readonly LicenseService $licenses,
        private readonly ProfileService $profiles,
        private readonly AgentApplicationNextStepService $nextStepService,
        private readonly ApplicationPaymentService $payments,
        private readonly AppointmentService $appointments,
        private readonly TestResultService $testResults,
        private readonly TestProgressionService $progression,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function execute(User $user, AIAgentAction $action): array
    {
        if (AgentSafetyRules::isAdminOnlyAction($action->action_name)) {
            throw new ApiException('This action requires an authorized employee.', 403);
        }

        if (! AgentSafetyRules::isPhase9bExecutable($action->action_name)) {
            throw new ApiException('This action cannot be executed yet. Please use the standard API endpoints.', 422);
        }

        if ($this->requiresApprovedProfile($action->action_name)) {
            $this->profiles->assertCanUseCitizenServices($user);
        }

        $arguments = is_array($action->arguments) ? $action->arguments : [];

        return match ($action->action_name) {
            'create_application' => $this->executeCreateApplication($user, $arguments),
            'get_application_status' => $this->executeGetApplicationStatus($user, $arguments),
            'get_application_next_step' => $this->executeGetApplicationNextStep($user, $arguments),
            'get_required_documents' => $this->executeGetRequiredDocuments($user, $arguments),
            'get_application_fee' => $this->executeGetApplicationFee($user, $arguments),
            'get_profile_status' => $this->executeGetProfileStatus($user),
            'start_payment' => $this->executeStartPayment($user, $arguments),
            'get_fines' => $this->executeGetFines($user),
            'get_licenses' => $this->executeGetLicenses($user),
            'get_available_tests' => $this->executeGetAvailableTests($user, $arguments),
            'get_appointment_slots' => $this->executeGetAppointmentSlots($user, $arguments),
            'get_current_appointments' => $this->executeGetCurrentAppointments($user, $arguments),
            'book_appointment' => $this->executeBookAppointment($user, $arguments),
            'reschedule_appointment' => $this->executeRescheduleAppointment($user, $arguments),
            'cancel_appointment' => $this->executeCancelAppointment($user, $arguments),
            'get_test_results' => $this->executeGetTestResults($user, $arguments),
            'submit_documents_for_review' => $this->executeSubmitDocumentsForReview($user, $arguments),
            default => throw new ApiException('Unsupported AI agent action.', 422),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeCreateApplication(User $user, array $arguments): array
    {
        $application = $this->applications->createFromPayload($user, $arguments);
        $application->loadMissing(['licenseType', 'serviceType', 'relatedLicense']);

        return [
            'application_id' => $application->id,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
            'service_type_code' => $application->serviceType?->code,
            'related_license_id' => $application->related_license_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetApplicationStatus(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $application->loadMissing(['licenseType', 'serviceType']);

        $step = $this->nextStepService->nextStepForApplication($application);

        return array_merge(
            (new ApplicationResource($application))->resolve(),
            [
                'status_label_ar' => $step['status_label_ar'],
                'next_step_key' => $step['next_step_key'],
                'next_step_message' => $step['next_step_message'],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetApplicationNextStep(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $application->loadMissing(['licenseType', 'serviceType']);

        $step = $this->nextStepService->nextStepForApplication($application);

        return [
            'application_id' => $application->id,
            'application_number' => $application->application_number,
            'status' => $step['status'],
            'status_label_ar' => $step['status_label_ar'],
            'next_step_key' => $step['next_step_key'],
            'next_step_message' => $step['next_step_message'],
            'suggested_action' => $step['suggested_action'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetRequiredDocuments(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $checklist = $this->documents->requiredChecklist($user, $applicationId);

        return [
            'application_id' => $applicationId,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
            'required_documents' => $checklist,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetApplicationFee(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $feeData = $this->payments->getFeeForApplication($user, $applicationId);
        $fee = $feeData['fee'];

        return [
            'application_id' => $applicationId,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
            'fee' => [
                'id' => $fee->id,
                'code' => $fee->code,
                'amount' => $fee->amount,
                'currency' => $fee->currency,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeGetProfileStatus(User $user): array
    {
        return $this->profiles->statusPayload($user);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeStartPayment(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $payment = $this->payments->createPendingPayment($user, $applicationId);

        return [
            'application_id' => $applicationId,
            'application_number' => $application->application_number,
            'payment_id' => $payment['payment']->id,
            'checkout_url' => $payment['checkout_url'] ?? null,
            'status' => $payment['payment']->status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeGetFines(User $user): array
    {
        $fines = $this->fines->listForCitizen($user);

        return [
            'items' => FineResource::collection($fines)->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeGetLicenses(User $user): array
    {
        $licenses = $this->licenses->listForCitizen($user);

        return [
            'items' => LicenseResource::collection($licenses)->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetAvailableTests(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $payload = $this->appointments->availableTestsForApplication($user, $applicationId);

        return array_merge([
            'application_id' => $applicationId,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
        ], $payload);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetAppointmentSlots(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $testType = $this->resolveTestTypeFromArguments($arguments, $application);

        $slots = $this->appointments->listAvailableSlots($testType->id);

        return [
            'application_id' => $applicationId,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
            'test_type' => [
                'id' => $testType->id,
                'code' => $testType->code,
                'name' => $testType->name,
            ],
            'slots' => collect(AppointmentSlotResource::collection($slots)->resolve())
                ->map(function (array $slot): array {
                    $slot['available_capacity'] = $slot['remaining_capacity'] ?? null;

                    return $slot;
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetCurrentAppointments(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);
        $appointments = $this->appointments->listApplicationAppointments($user, $applicationId);

        $booked = $appointments->filter(
            fn (TestAppointment $appointment): bool => $appointment->status === AppointmentStatus::Booked
        );

        return [
            'application_id' => $applicationId,
            'application_number' => $application->application_number,
            'appointments' => $booked
                ->map(fn (TestAppointment $appointment): array => $this->formatAppointmentForAgent($appointment))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeBookAppointment(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $slotId = $arguments['appointment_slot_id'] ?? null;

        if (! is_numeric($slotId) || (int) $slotId < 1) {
            throw new ApiException('Appointment slot ID is required for this action.', 422, [
                'appointment_slot_id' => ['The appointment_slot_id argument is required.'],
            ]);
        }

        $appointment = $this->appointments->book($user, $applicationId, (int) $slotId)
            ->loadMissing(['application', 'testType', 'appointmentSlot.appointmentCenter']);

        return $this->formatAppointmentForAgent($appointment, includeApplicationMeta: true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeRescheduleAppointment(User $user, array $arguments): array
    {
        $appointmentId = $arguments['appointment_id'] ?? null;
        $slotId = $arguments['appointment_slot_id'] ?? null;

        if (! is_numeric($appointmentId) || (int) $appointmentId < 1) {
            throw new ApiException('Appointment ID is required for this action.', 422, [
                'appointment_id' => ['The appointment_id argument is required.'],
            ]);
        }

        if (! is_numeric($slotId) || (int) $slotId < 1) {
            throw new ApiException('Appointment slot ID is required for this action.', 422, [
                'appointment_slot_id' => ['The appointment_slot_id argument is required.'],
            ]);
        }

        $appointment = $this->appointments->reschedule($user, (int) $appointmentId, (int) $slotId)
            ->loadMissing(['application', 'testType', 'appointmentSlot.appointmentCenter']);

        return $this->formatAppointmentForAgent($appointment, includeApplicationMeta: true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeCancelAppointment(User $user, array $arguments): array
    {
        $appointmentId = $arguments['appointment_id'] ?? null;
        if (! is_numeric($appointmentId) || (int) $appointmentId < 1) {
            throw new ApiException('Appointment ID is required for this action.', 422, [
                'appointment_id' => ['The appointment_id argument is required.'],
            ]);
        }

        $appointment = $this->appointments->cancel(
            $user,
            (int) $appointmentId,
            isset($arguments['reason']) ? (string) $arguments['reason'] : null
        )->loadMissing(['application', 'testType', 'appointmentSlot.appointmentCenter']);

        return $this->formatAppointmentForAgent($appointment, includeApplicationMeta: true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeGetTestResults(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->applications->getForCitizen($user, $applicationId);

        $results = $this->testResults->listForApplication($user, $applicationId);

        return [
            'application_id' => $applicationId,
            'application_number' => $application->application_number,
            'items' => TestResultResource::collection($results)->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function executeSubmitDocumentsForReview(User $user, array $arguments): array
    {
        $applicationId = $this->requireApplicationId($arguments);
        $application = $this->documents->submitForReview($user, $applicationId);

        return [
            'application_id' => $applicationId,
            'application_number' => $application->application_number,
            'status' => $application->status->value,
            'status_label_ar' => ApplicationStatusLabelMapper::labelAr($application->status),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAppointmentForAgent(
        TestAppointment $appointment,
        bool $includeApplicationMeta = false,
    ): array {
        $appointment->loadMissing(['testType', 'appointmentSlot.appointmentCenter']);
        $slot = $appointment->appointmentSlot;

        $payload = [
            'appointment_id' => $appointment->id,
            'id' => $appointment->id,
            'status' => $appointment->status->value,
            'scheduled_at' => $appointment->scheduled_at?->toIso8601String(),
            'date' => $slot?->date?->format('Y-m-d') ?? $appointment->scheduled_at?->format('Y-m-d'),
            'start_time' => $slot?->start_time ?? $appointment->scheduled_at?->format('H:i'),
            'end_time' => $slot?->end_time,
            'test_type' => [
                'id' => $appointment->testType?->id,
                'code' => $appointment->testType?->code,
                'name' => $appointment->testType?->name,
            ],
            'center' => $slot !== null
                ? AppointmentSlotResource::resolveCenterPayload($slot)
                : null,
            'appointment_slot_id' => $appointment->appointment_slot_id,
        ];

        if ($includeApplicationMeta) {
            $payload['application_id'] = $appointment->application_id;
            $payload['application_number'] = $appointment->application?->application_number;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveTestTypeFromArguments(array $arguments, \App\Models\LicenseApplication $application): \App\Models\TestType
    {
        if (isset($arguments['test_type_id']) && is_numeric($arguments['test_type_id'])) {
            $testType = TestType::query()
                ->whereKey((int) $arguments['test_type_id'])
                ->where('is_active', true)
                ->first();

            if ($testType !== null) {
                return $testType;
            }
        }

        $code = trim((string) ($arguments['test_type_code'] ?? ''));
        if ($code !== '') {
            $testType = TestType::query()->where('code', $code)->where('is_active', true)->first();
            if ($testType !== null) {
                return $testType;
            }
        }

        $bookable = $this->progression->resolveBookableTestType($application);
        if ($bookable !== null) {
            return $bookable;
        }

        throw new ApiException('Test type is required for this action.', 422, [
            'test_type_id' => ['The test_type_id argument is required.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requireApplicationId(array $arguments): int
    {
        $applicationId = $arguments['application_id'] ?? null;

        if (! is_numeric($applicationId) || (int) $applicationId < 1) {
            throw new ApiException('Application ID is required for this action.', 422, [
                'application_id' => ['The application_id argument is required.'],
            ]);
        }

        return (int) $applicationId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveLicenseType(array $arguments): LicenseType
    {
        $code = trim((string) ($arguments['license_type_code'] ?? ''));

        if ($code === '') {
            throw new ApiException('License type is required.', 422, [
                'license_type_code' => ['The license_type_code argument is required.'],
            ]);
        }

        $licenseType = LicenseType::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($licenseType === null) {
            throw new ApiException('Invalid or inactive license type.', 422, [
                'license_type_code' => ['The selected license type is invalid.'],
            ]);
        }

        return $licenseType;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveServiceType(array $arguments): ServiceType
    {
        $code = trim((string) ($arguments['service_type_code'] ?? 'new_license'));

        $serviceType = ServiceType::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($serviceType === null) {
            throw new ApiException('Invalid or inactive service type.', 422, [
                'service_type_code' => ['The selected service type is invalid.'],
            ]);
        }

        return $serviceType;
    }

    private function requiresApprovedProfile(string $actionName): bool
    {
        return in_array($actionName, [
            'create_application',
            'start_payment',
            'book_appointment',
            'submit_documents_for_review',
        ], true);
    }
}

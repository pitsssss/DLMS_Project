<?php

namespace App\Modules\AIAgent\Services;

use App\Models\LicenseApplication;
use App\Models\TestType;
use App\Modules\AIAgent\DTO\AgentWorkflowContext;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentTestTypeExtractor;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\AgentWorkflowPhraseMatcher;
use App\Modules\Appointments\Services\AppointmentService;
use App\Modules\Appointments\Services\TestProgressionService;

class AgentAppointmentHandler
{
    public function __construct(
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
        private readonly AgentApplicationActionPolicy $policy,
        private readonly AppointmentService $appointments,
        private readonly TestProgressionService $progression,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(AgentWorkflowContext $context, AgentIntent $intent): array
    {
        if ($context->hasNoApplications()) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->noApplicationReply('appointment'),
                'proposed_action' => null,
                'execute_immediately' => false,
            ]);
        }

        if ($context->hasMultipleApplications()) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->multipleApplicationsReply('appointment', $context->applicationChoices, $context->language),
                'proposed_action' => null,
                'missing_slots' => ['application_choice'],
                'execute_immediately' => false,
            ]);
        }

        $application = $context->targetApplication;
        if ($application === null) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->noApplicationReply('appointment'),
                'proposed_action' => null,
                'execute_immediately' => false,
            ]);
        }

        if ($intent === AgentIntent::BookAppointment) {
            if (AgentWorkflowPhraseMatcher::isBookFirstAvailableSlotQuery($context->message)) {
                return $this->buildBookFirstSlotPayload($context, $application);
            }

            $blockReason = $this->policy->blockReason($application, 'book_appointment');
            if ($blockReason !== null) {
                return $this->responseBuilder->blockedPayload($intent, $context->language, $blockReason);
            }

            // Book flow: real slot buttons via pending_workflow (text or token selection).
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.appointments.slots.choose'),
                'proposed_action' => null,
                'missing_slots' => ['appointment_slot_choice'],
                'requires_confirmation' => false,
                'execute_immediately' => false,
                'collected_slots' => ['application_id' => $application->id],
            ]);
        }

        $actionName = 'get_appointment_slots';
        $blockReason = $this->policy->blockReason($application, $actionName);
        if ($blockReason !== null) {
            return $this->responseBuilder->blockedPayload($intent, $context->language, $blockReason);
        }

        return $this->buildSlotsPayload($context, $application, AgentIntent::GetAppointmentSlots);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRescheduleOrCancelPayload(AgentWorkflowContext $context, AgentIntent $intent): array
    {
        if ($context->hasNoApplications()) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->noApplicationReply('appointment'),
                'proposed_action' => null,
                'execute_immediately' => false,
            ]);
        }

        if ($context->hasMultipleApplications()) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->multipleApplicationsReply('appointment', $context->applicationChoices, $context->language),
                'proposed_action' => null,
                'missing_slots' => ['application_choice'],
                'execute_immediately' => false,
            ]);
        }

        $application = $context->targetApplication;
        if ($application === null) {
            return $this->responseBuilder->basePayload($intent, $context->language, [
                'reply' => $this->policy->noApplicationReply('appointment'),
                'proposed_action' => null,
                'execute_immediately' => false,
            ]);
        }

        return $this->responseBuilder->basePayload($intent, $context->language, [
            'reply' => AgentTranslator::message('ai_agent.appointments.choose.select'),
            'proposed_action' => null,
            'missing_slots' => ['appointment_choice'],
            'requires_confirmation' => false,
            'execute_immediately' => false,
            'collected_slots' => ['application_id' => $application->id],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildCurrentAppointmentsPayload(AgentWorkflowContext $context): array
    {
        if ($context->hasNoApplications()) {
            return $this->responseBuilder->basePayload(AgentIntent::GetCurrentAppointments, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.appointments.current.no_application'),
                'proposed_action' => null,
                'execute_immediately' => false,
            ]);
        }

        if ($context->hasMultipleApplications()) {
            $summary = ($context->applicationChoices ?? collect())
                ->map(function (LicenseApplication $application): string {
                    $licenseLabel = \App\Modules\AIAgent\Support\LicenseTypeSlotExtractor::labelAr(
                        (string) ($application->licenseType?->code ?? '')
                    );
                    $statusLabel = \App\Modules\AIAgent\Support\ApplicationStatusLabelMapper::labelAr($application->status);

                    return '- '.$application->application_number.' — رخصة قيادة '.$licenseLabel.' — '.$statusLabel;
                })
                ->implode("\n");

            return $this->responseBuilder->basePayload(AgentIntent::GetCurrentAppointments, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.appointments.current.choose_application', [
                    'summary' => $summary,
                ]),
                'proposed_action' => null,
                'missing_slots' => ['application_choice'],
                'execute_immediately' => false,
            ]);
        }

        $application = $context->targetApplication;
        if ($application === null) {
            return $this->responseBuilder->basePayload(AgentIntent::GetCurrentAppointments, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.appointments.current.no_application'),
                'proposed_action' => null,
                'execute_immediately' => false,
            ]);
        }

        return $this->responseBuilder->basePayload(AgentIntent::GetCurrentAppointments, $context->language, [
            'reply' => 'سأعرض لك موعدك المحجوز لهذا الطلب.',
            'proposed_action' => [
                'name' => 'get_current_appointments',
                'arguments' => [
                    'application_id' => $application->id,
                ],
            ],
            'requires_confirmation' => false,
            'execute_immediately' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function replyFromCurrentAppointmentsResult(array $result): string
    {
        $appointments = $result['appointments'] ?? [];
        if (! is_array($appointments) || $appointments === []) {
            return AgentTranslator::message('ai_agent.appointments.current.none');
        }

        if (count($appointments) === 1) {
            $appointment = $appointments[0];

            return AgentTranslator::message('ai_agent.appointments.current.single', [
                'test' => trim((string) ($appointment['test_type']['name'] ?? AgentTranslator::message('ai_agent.appointments.test_fallback'))),
                'date' => trim((string) ($appointment['date'] ?? '')),
                'time' => trim((string) ($appointment['start_time'] ?? '')),
            ]);
        }

        $lines = collect($appointments)
            ->map(function (array $appointment): string {
                $testName = trim((string) ($appointment['test_type']['name'] ?? AgentTranslator::message('ai_agent.appointments.test_fallback')));
                $date = trim((string) ($appointment['date'] ?? ''));
                $time = trim((string) ($appointment['start_time'] ?? ''));

                return AgentTranslator::message('ai_agent.appointments.current.line', [
                    'test' => $testName,
                    'date' => $date,
                    'time' => $time,
                ]);
            })
            ->implode("\n");

        return AgentTranslator::message('ai_agent.appointments.current.multiple')."\n".$lines;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function replyFromSlotsResult(array $result): string
    {
        $testName = trim((string) ($result['test_type']['name'] ?? AgentTranslator::message('ai_agent.appointments.test_fallback')));
        $slots = $result['slots'] ?? [];

        if (! is_array($slots) || $slots === []) {
            return AgentTranslator::message('ai_agent.appointments.slots.none_for_test', ['test' => $testName]);
        }

        return AgentTranslator::message('ai_agent.appointments.slots.choose_for_test', ['test' => $testName]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildSlotsPayload(
        AgentWorkflowContext $context,
        LicenseApplication $application,
        AgentIntent $intent,
        array $overrides = [],
    ): array {
        $blockReason = $this->policy->blockReason($application, 'get_appointment_slots');
        if ($blockReason !== null) {
            return $this->responseBuilder->blockedPayload($intent, $context->language, $blockReason);
        }

        $resolution = $this->resolveTestType($context, $application);
        if ($resolution['error'] !== null) {
            return $this->responseBuilder->blockedPayload($intent, $context->language, $resolution['error']);
        }

        /** @var TestType $testType */
        $testType = $resolution['test_type'];

        return $this->responseBuilder->basePayload(AgentIntent::GetAppointmentSlots, $context->language, array_merge([
            'reply' => 'سأعرض لك المواعيد المتاحة لـ'.$testType->name.'.',
            'proposed_action' => [
                'name' => 'get_appointment_slots',
                'arguments' => [
                    'application_id' => $application->id,
                    'test_type_id' => $testType->id,
                    'test_type_code' => $testType->code,
                ],
            ],
            'requires_confirmation' => false,
            'execute_immediately' => true,
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildBookFirstSlotPayload(AgentWorkflowContext $context, LicenseApplication $application): array
    {
        $blockReason = $this->policy->blockReason($application, 'book_appointment');
        if ($blockReason !== null) {
            return $this->responseBuilder->blockedPayload(AgentIntent::BookAppointment, $context->language, $blockReason);
        }

        $resolution = $this->resolveTestType($context, $application);
        if ($resolution['error'] !== null) {
            return $this->responseBuilder->blockedPayload(AgentIntent::BookAppointment, $context->language, $resolution['error']);
        }

        /** @var TestType $testType */
        $testType = $resolution['test_type'];
        $slots = $this->appointments->listAvailableSlots($testType->id);

        if ($slots->isEmpty()) {
            return $this->responseBuilder->basePayload(AgentIntent::GetAppointmentSlots, $context->language, [
                'reply' => 'لا توجد مواعيد متاحة حالياً لـ'.$testType->name.'. يرجى المحاولة لاحقاً.',
                'proposed_action' => [
                    'name' => 'get_appointment_slots',
                    'arguments' => [
                        'application_id' => $application->id,
                        'test_type_id' => $testType->id,
                        'test_type_code' => $testType->code,
                    ],
                ],
                'requires_confirmation' => false,
                'execute_immediately' => true,
            ]);
        }

        $firstSlot = $slots->first();

        return $this->responseBuilder->basePayload(AgentIntent::BookAppointment, $context->language, [
            'reply' => 'يمكنني حجز أول موعد متاح لـ'.$testType->name.' بتاريخ '
                .$firstSlot->date?->format('Y-m-d')
                .' الساعة '
                .$firstSlot->start_time
                .'. هل تريد تأكيد الحجز؟',
            'proposed_action' => [
                'name' => 'book_appointment',
                'arguments' => [
                    'application_id' => $application->id,
                    'test_type_id' => $testType->id,
                    'test_type_code' => $testType->code,
                    'appointment_slot_id' => $firstSlot->id,
                ],
            ],
            'requires_confirmation' => true,
            'execute_immediately' => false,
        ]);
    }

    /**
     * @return array{test_type: ?TestType, error: ?string}
     */
    private function resolveTestType(AgentWorkflowContext $context, LicenseApplication $application): array
    {
        $explicitCode = AgentTestTypeExtractor::extractFromMessage($context->message);
        if ($explicitCode !== null) {
            $testType = TestType::query()
                ->where('code', $explicitCode)
                ->where('is_active', true)
                ->first();

            if ($testType === null) {
                return [
                    'test_type' => null,
                    'error' => 'نوع الاختبار المطلوب غير معروف.',
                ];
            }

            return ['test_type' => $testType, 'error' => null];
        }

        $bookable = $this->progression->resolveBookableTestType($application);
        if ($bookable !== null) {
            return ['test_type' => $bookable, 'error' => null];
        }

        $tests = $this->appointments->availableTestsForApplication($context->citizen, $application->id);
        $availableTests = collect($tests)->filter(fn (array $test): bool => ! empty($test['is_available']));

        if ($availableTests->count() === 1) {
            $testType = TestType::query()->find((int) $availableTests->first()['test_type_id']);
            if ($testType !== null) {
                return ['test_type' => $testType, 'error' => null];
            }
        }

        if ($availableTests->count() > 1) {
            return [
                'test_type' => null,
                'error' => 'لديك أكثر من اختبار متاح. من فضلك حدد نوع الاختبار الذي تريد عرض مواعيده.',
            ];
        }

        $firstReason = trim((string) (collect($tests)->first()['reason'] ?? ''));
        if ($firstReason !== '') {
            return ['test_type' => null, 'error' => $firstReason];
        }

        return [
            'test_type' => null,
            'error' => 'لا يوجد اختبار متاح للحجز حالياً. يرجى متابعة الخطوة الحالية لطلبك.',
        ];
    }
}

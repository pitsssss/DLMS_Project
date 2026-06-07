<?php

namespace App\Modules\AIAgent\Services;

use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\AgentWorkflowIntentCatalog;

class AgentWorkflowOrchestrator
{
    public function __construct(
        private readonly AgentWorkflowContextResolver $contextResolver,
        private readonly AgentWorkflowIntentResolver $intentResolver,
        private readonly AgentWorkflowResponseBuilder $responseBuilder,
        private readonly AgentSessionContextService $sessionContext,
        private readonly AgentApplicationStatusHandler $applicationStatusHandler,
        private readonly AgentApplicationNextStepService $applicationNextStepService,
        private readonly AgentRequiredDocumentsHandler $requiredDocumentsHandler,
        private readonly AgentFeeAndPaymentHandler $feeAndPaymentHandler,
        private readonly AgentAppointmentHandler $appointmentHandler,
        private readonly AgentAvailableTestsHandler $availableTestsHandler,
        private readonly AgentFinesHandler $finesHandler,
        private readonly AgentLicensesHandler $licensesHandler,
        private readonly AgentProfileStatusHandler $profileStatusHandler,
    ) {}

    /**
     * Resolve a workflow-aware payload from deterministic rules.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null Null when no deterministic workflow match.
     */
    public function resolveDeterministicPayload(
        User $citizen,
        string $message,
        AIAgentSession $session,
        string $language,
        array $state,
    ): ?array {
        $lastApplicationId = $this->sessionContext->resolveLastDiscussedApplicationId($session);
        $intent = $this->intentResolver->resolve(
            $message,
            $state['intent'] ?? $session->current_intent,
            $lastApplicationId
        );

        if ($intent === null) {
            return null;
        }

        $context = $this->contextResolver->resolve($citizen, $session, $message, $language, $state);

        return match ($intent) {
            AgentIntent::AdminActionDenied => $this->adminDeniedResponse($language),
            AgentIntent::OutOfScope => $this->outOfScopeResponse($language),
            AgentIntent::GetApplicationStatus => $this->applicationStatusHandler->buildPayload($citizen, $language),
            AgentIntent::GetApplicationNextStep => $this->applicationNextStepService->buildPayload($citizen, $session, $language),
            AgentIntent::GetRequiredDocuments => $this->requiredDocumentsHandler->buildPayload($citizen, $session, $language),
            AgentIntent::GetApplicationFee, AgentIntent::StartPayment => $this->feeAndPaymentHandler->buildPayload($context, $intent),
            AgentIntent::GetAvailableTests => $this->availableTestsHandler->buildPayload($context),
            AgentIntent::GetCurrentAppointments => $this->appointmentHandler->buildCurrentAppointmentsPayload($context),
            AgentIntent::GetAppointmentSlots, AgentIntent::BookAppointment => $this->appointmentHandler->buildPayload($context, $intent),
            AgentIntent::GetTestResults => $this->testResultsPayload($context),
            AgentIntent::GetFines => $this->finesHandler->buildPayload($context),
            AgentIntent::GetLicenses => $this->licensesHandler->buildPayload($context),
            AgentIntent::GetProfileStatus => $this->profileStatusHandler->buildPayload($context),
            AgentIntent::CreateNewLicenseApplication => $this->newLicensePayload($citizen, $session, $message, $language, $state),
            default => null,
        };
    }

    /**
     * Override Gemini payload when a clear workflow intent is detected.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>|null
     */
    public function overridePayload(
        User $citizen,
        string $message,
        AIAgentSession $session,
        string $language,
        array $state,
    ): ?array {
        return $this->resolveDeterministicPayload($citizen, $message, $session, $language, $state);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function newLicensePayload(
        User $citizen,
        AIAgentSession $session,
        string $message,
        string $language,
        array $state,
    ): array {
        if ($this->sessionContext->isNewLicenseContinuation($state, $state['extracted_license_type'] ?? null)) {
            return $this->sessionContext->applyContinuity(
                $citizen,
                $session,
                $this->generalHelpShape($language),
                $state,
                $message
            );
        }

        $licenseType = $state['collected_slots']['license_type_code'] ?? null;

        if ($licenseType === null) {
            return $this->responseBuilder->basePayload(AgentIntent::CreateNewLicenseApplication, $language, [
                'confidence' => 0.72,
                'reply' => $language === 'ar'
                    ? 'يمكنني مساعدتك في إنشاء طلب رخصة جديدة. ما نوع الرخصة التي تريدها؟ خاصة، عامة، شاحنة، أم حافلة؟'
                    : 'I can help you prepare a new license application. Which license type do you need: private, public, truck, or bus?',
                'missing_slots' => ['license_type'],
                'proposed_action' => null,
            ]);
        }

        return $this->sessionContext->applyContinuity(
            $citizen,
            $session,
            $this->generalHelpShape($language),
            $state,
            $message
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function testResultsPayload(\App\Modules\AIAgent\DTO\AgentWorkflowContext $context): array
    {
        if ($context->hasNoApplications()) {
            return $this->responseBuilder->basePayload(AgentIntent::GetTestResults, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.workflow.no_application.test_results'),
                'proposed_action' => null,
            ]);
        }

        if ($context->hasMultipleApplications()) {
            return $this->responseBuilder->basePayload(AgentIntent::GetTestResults, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.workflow.multiple_applications.test_results', [
                    'summary' => '',
                ]),
                'missing_slots' => ['application_choice'],
                'proposed_action' => null,
            ]);
        }

        $application = $context->targetApplication;
        if ($application === null) {
            return $this->responseBuilder->basePayload(AgentIntent::GetTestResults, $context->language, [
                'reply' => AgentTranslator::message('ai_agent.workflow.no_application.test_results'),
                'proposed_action' => null,
            ]);
        }

        return $this->responseBuilder->basePayload(AgentIntent::GetTestResults, $context->language, [
            'reply' => 'سأعرض لك نتائج اختبارات طلبك. يرجى مراجعة تفاصيل كل اختبار في النتيجة.',
            'proposed_action' => [
                'name' => 'get_test_results',
                'arguments' => ['application_id' => $application->id],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function generalHelpShape(string $language): array
    {
        return $this->responseBuilder->basePayload(AgentIntent::GeneralHelp, $language, [
            'confidence' => 0.45,
            'reply' => $language === 'ar'
                ? 'أنا مساعد خدمات رخص القيادة. يمكنني مساعدتك في طلب رخصة جديدة، متابعة الطلب، المستندات، الدفع، المواعيد، النتائج، الرخص، والمخالفات. كيف يمكنني مساعدتك؟'
                : 'I assist with driving license services only.',
            'execute_immediately' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function adminDeniedResponse(string $language): array
    {
        return $this->responseBuilder->blockedPayload(
            AgentIntent::AdminActionDenied,
            $language,
            $language === 'ar'
                ? 'هذا الإجراء يتطلب موظفاً مخولاً. لا يمكنني تنفيذه نيابة عنك.'
                : 'This action requires an authorized employee.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function outOfScopeResponse(string $language): array
    {
        return $this->responseBuilder->basePayload(AgentIntent::OutOfScope, $language, [
            'confidence' => 0.9,
            'reply' => $language === 'ar'
                ? 'أنا مساعد خدمات رخص القيادة فقط. يرجى طرح سؤال متعلق بالرخصة أو الطلب أو المواعيد أو المستندات.'
                : 'I only support driving license services.',
            'execute_immediately' => false,
        ]);
    }
}

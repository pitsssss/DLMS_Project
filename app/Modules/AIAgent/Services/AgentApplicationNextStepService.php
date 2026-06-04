<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use Illuminate\Support\Collection;

class AgentApplicationNextStepService
{
    public function __construct(
        private readonly AgentSessionContextService $sessionContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(User $citizen, AIAgentSession $session, string $language = 'ar'): array
    {
        $resolution = $this->resolveTargetApplication($citizen, $session);

        if ($resolution === null) {
            return $this->basePayload($language, [
                'reply' => AgentTranslator::message('ai_agent.application_next_step.no_applications'),
                'proposed_action' => null,
            ]);
        }

        if ($resolution instanceof Collection) {
            return $this->basePayload($language, [
                'reply' => AgentTranslator::message('ai_agent.application_next_step.multiple_applications', [
                    'summary' => $this->formatApplicationList($resolution, $language),
                ]),
                'proposed_action' => null,
                'missing_slots' => ['application_choice'],
            ]);
        }

        $step = $this->nextStepForApplication($resolution, $language);

        return $this->basePayload($language, [
            'reply' => $step['reply'],
            'proposed_action' => [
                'name' => 'get_application_next_step',
                'arguments' => [
                    'application_id' => $resolution->id,
                ],
            ],
            'suggested_action' => $step['suggested_action'] ?? null,
        ]);
    }

    public function nextStepMessageForStatus(string $status): string
    {
        $normalized = trim($status);

        if ($normalized === '') {
            return AgentTranslator::message('ai_agent.application_next_step.unknown');
        }

        $key = 'ai_agent.application_next_step.'.$normalized;
        $message = AgentTranslator::message($key);

        if (str_starts_with($message, 'messages.')) {
            return AgentTranslator::message('ai_agent.application_next_step.unknown');
        }

        return $message;
    }

    /**
     * @return array{reply: string, next_step_key: string, next_step_message: string, suggested_action: string|null, status: string, status_label_ar: string}
     */
    public function nextStepForApplication(LicenseApplication $application, string $language = 'ar'): array
    {
        $status = $application->status instanceof ApplicationStatus
            ? $application->status
            : ApplicationStatus::tryFrom((string) $application->status) ?? ApplicationStatus::Draft;

        $key = $status->value;
        $statusLabel = ApplicationStatusLabelMapper::labelAr($status);
        $message = $this->nextStepMessageForStatus($key);

        if ($language === 'en') {
            return [
                'reply' => "Application {$application->application_number} ({$statusLabel}). {$message}",
                'next_step_key' => $key,
                'next_step_message' => $message,
                'suggested_action' => $this->suggestedActionForStatus($status),
                'status' => $status->value,
                'status_label_ar' => $statusLabel,
            ];
        }

        return [
            'reply' => $message,
            'next_step_key' => $key,
            'next_step_message' => $message,
            'suggested_action' => $this->suggestedActionForStatus($status),
            'status' => $status->value,
            'status_label_ar' => $statusLabel,
        ];
    }

    /**
     * @return array{reply: string, status_label_ar: string}
     */
    public function statusReplyWithNextStep(LicenseApplication $application, string $language = 'ar'): array
    {
        $step = $this->nextStepForApplication($application, $language);

        if ($language === 'en') {
            return [
                'reply' => "Application {$application->application_number} status: {$step['status_label_ar']}. {$step['reply']}",
                'status_label_ar' => $step['status_label_ar'],
            ];
        }

        return [
            'reply' => AgentTranslator::message('ai_agent.application_status.with_next_step', [
                'number' => $application->application_number,
                'status' => $step['status_label_ar'],
                'next_step' => $step['next_step_message'],
            ]),
            'status_label_ar' => $step['status_label_ar'],
        ];
    }

    /**
     * @return LicenseApplication|Collection<int, LicenseApplication>|null
     */
    public function resolveTargetApplication(User $citizen, AIAgentSession $session): LicenseApplication|Collection|null
    {
        $applicationId = $this->sessionContext->resolveLastDiscussedApplicationId($session);

        if ($applicationId !== null) {
            $application = LicenseApplication::query()
                ->where('citizen_id', $citizen->id)
                ->whereKey($applicationId)
                ->with(['licenseType', 'serviceType'])
                ->first();

            if ($application !== null) {
                return $application;
            }
        }

        $applications = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->with(['licenseType', 'serviceType'])
            ->orderByDesc('id')
            ->get();

        if ($applications->isEmpty()) {
            return null;
        }

        $active = $applications->filter(
            fn (LicenseApplication $application): bool => $application->status instanceof ApplicationStatus
                ? $application->status->isActive()
                : in_array((string) $application->status, ApplicationStatus::activeValues(), true)
        )->values();

        if ($active->count() === 1) {
            return $active->first();
        }

        if ($active->count() > 1) {
            return $active;
        }

        if ($applications->count() === 1) {
            return $applications->first();
        }

        return $applications;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(string $language, array $overrides): array
    {
        return array_merge([
            'intent' => AgentIntent::GetApplicationNextStep->value,
            'confidence' => 0.93,
            'language' => $language,
            'missing_slots' => [],
            'requires_confirmation' => false,
            'execute_immediately' => true,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ], $overrides);
    }

    private function suggestedActionForStatus(ApplicationStatus $status): ?string
    {
        return match ($status) {
            ApplicationStatus::Draft => 'get_required_documents',
            ApplicationStatus::DocumentsRejected => 'get_required_documents',
            ApplicationStatus::PaymentPending => 'start_payment',
            ApplicationStatus::PaymentCompleted, ApplicationStatus::AppointmentPending => 'get_available_tests',
            ApplicationStatus::WaitingRetest => 'get_appointment_slots',
            ApplicationStatus::LicenseIssued => 'get_licenses',
            default => null,
        };
    }

    /**
     * @param  Collection<int, LicenseApplication>  $applications
     */
    private function formatApplicationList(Collection $applications, string $language): string
    {
        return $applications
            ->map(function (LicenseApplication $application) use ($language): string {
                $licenseLabel = LicenseTypeSlotExtractor::labelAr(
                    (string) ($application->licenseType?->code ?? '')
                );
                $statusLabel = ApplicationStatusLabelMapper::labelAr($application->status);

                if ($language === 'en') {
                    return '- '.$application->application_number.' ('.$licenseLabel.'): '.$statusLabel;
                }

                return '- '.$application->application_number.' — رخصة قيادة '.$licenseLabel.' — '.$statusLabel;
            })
            ->implode("\n");
    }
}

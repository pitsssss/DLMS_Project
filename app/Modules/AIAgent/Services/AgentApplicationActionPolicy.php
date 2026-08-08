<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\Support\AgentApplicationStatusMap;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use App\Modules\Applications\Support\ServiceWorkflow;
use Illuminate\Support\Collection;

class AgentApplicationActionPolicy
{
    public function blockReason(LicenseApplication $application, string $actionName): ?string
    {
        $application->loadMissing('serviceType');
        $actionName = AgentApplicationStatusMap::normalizeAction($actionName);

        if (! ServiceWorkflow::requiresTests($application->serviceType?->code)
            && in_array($actionName, [
                'get_available_tests',
                'get_appointment_slots',
                'book_appointment',
                'reschedule_appointment',
                'cancel_appointment',
            ], true)) {
            return AgentTranslator::message('ai_agent.policy.tests_not_required');
        }

        $status = $application->status instanceof ApplicationStatus
            ? $application->status
            : ApplicationStatus::tryFrom((string) $application->status) ?? ApplicationStatus::Draft;

        if (AgentApplicationStatusMap::isActionAllowed($status, $actionName)) {
            return null;
        }

        $definition = AgentApplicationStatusMap::definition($status);
        $labels = $this->localizedStageLabels($status, $definition);

        return match ($actionName) {
            'start_payment' => AgentTranslator::message('ai_agent.policy.cannot_pay', $labels),
            'get_available_tests' => $this->availableTestsBlockReason($status, $labels),
            'get_appointment_slots' => $this->appointmentSlotsBlockReason($status, $labels),
            'book_appointment' => AgentTranslator::message('ai_agent.policy.cannot_book_appointment', [
                'next_step' => $labels['next_step'],
            ]),
            'get_application_fee' => AgentTranslator::message('ai_agent.policy.cannot_show_fee', $labels),
            default => AgentTranslator::message('ai_agent.policy.action_blocked', $labels),
        };
    }

    public function profileBlockReason(User $citizen, bool $mutating): ?string
    {
        if (! $mutating || ! $citizen->isCitizen()) {
            return null;
        }

        $status = (string) ($citizen->profile_status?->value ?? $citizen->profile_status ?? '');

        return match ($status) {
            'pending_review' => AgentTranslator::message('ai_agent.profile.pending_review'),
            'rejected' => AgentTranslator::message('ai_agent.profile.rejected'),
            default => ! $citizen->profile_completed
                ? AgentTranslator::message('ai_agent.profile.incomplete')
                : null,
        };
    }

    public function noApplicationReply(string $intentKey): string
    {
        return AgentTranslator::message('ai_agent.workflow.no_application.'.$intentKey);
    }

    public function multipleApplicationsReply(string $intentKey, Collection $applications, string $language = 'ar'): string
    {
        $summary = $applications
            ->map(function (LicenseApplication $application): string {
                $licenseCode = (string) ($application->licenseType?->code ?? '');
                $licenseLabel = AgentTranslator::getLocale() === 'en'
                    ? LicenseTypeSlotExtractor::labelEn($licenseCode)
                    : LicenseTypeSlotExtractor::labelAr($licenseCode);
                $statusLabel = ApplicationStatusLabelMapper::label($application->status);

                return AgentTranslator::message('ai_agent.selection.application_summary_line', [
                    'number' => $application->application_number,
                    'license' => $licenseLabel,
                    'status' => $statusLabel,
                ]);
            })
            ->implode("\n");

        return AgentTranslator::message('ai_agent.workflow.multiple_applications.'.$intentKey, [
            'summary' => $summary,
        ]);
    }

    /**
     * @param  array{stage: string, next_step: string}  $labels
     */
    private function availableTestsBlockReason(ApplicationStatus $status, array $labels): string
    {
        if ($status === ApplicationStatus::PaymentPending) {
            return AgentTranslator::message('ai_agent.policy.tests_before_payment', [
                'next_step' => $labels['next_step'],
            ]);
        }

        if ($status === ApplicationStatus::Draft) {
            return AgentTranslator::message('ai_agent.policy.tests_while_draft', [
                'next_step' => $labels['next_step'],
            ]);
        }

        return AgentTranslator::message('ai_agent.policy.tests_blocked', $labels);
    }

    /**
     * @param  array{stage: string, next_step: string}  $labels
     */
    private function appointmentSlotsBlockReason(ApplicationStatus $status, array $labels): string
    {
        if ($status === ApplicationStatus::PaymentPending) {
            return AgentTranslator::message('ai_agent.policy.slots_before_payment', [
                'next_step' => $labels['next_step'],
            ]);
        }

        if ($status === ApplicationStatus::Draft) {
            return AgentTranslator::message('ai_agent.policy.slots_while_draft', [
                'next_step' => $labels['next_step'],
            ]);
        }

        return AgentTranslator::message('ai_agent.policy.slots_blocked', $labels);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{stage: string, next_step: string}
     */
    private function localizedStageLabels(ApplicationStatus $status, array $definition): array
    {
        if (AgentTranslator::getLocale() === 'en') {
            return [
                'stage' => ApplicationStatusLabelMapper::labelEn($status),
                'next_step' => $this->nextStepEn($status),
            ];
        }

        return [
            'stage' => (string) ($definition['label_ar'] ?? ''),
            'next_step' => (string) ($definition['next_step_ar'] ?? ''),
        ];
    }

    private function nextStepEn(ApplicationStatus $status): string
    {
        return match ($status) {
            ApplicationStatus::Draft => 'upload the required documents and submit them for review',
            ApplicationStatus::DocumentsUnderReview => 'wait for employee review',
            ApplicationStatus::DocumentsRejected => 'review the rejection reason and re-upload the documents',
            ApplicationStatus::PaymentPending => 'pay the fees',
            ApplicationStatus::PaymentCompleted => 'book an appointment for the first available test',
            ApplicationStatus::AppointmentPending => 'book an appointment for the available test',
            ApplicationStatus::InTesting => 'follow the current test or wait for the result to be recorded',
            ApplicationStatus::WaitingRetest => 'book a retest appointment for the same failed test',
            ApplicationStatus::Approved => 'wait for the license to be issued by the relevant employee',
            ApplicationStatus::AdministrativeReview => 'wait for the administrative decision',
            ApplicationStatus::LicenseIssued => 'view the license details',
            ApplicationStatus::Rejected => 'review the rejection reason',
            ApplicationStatus::Cancelled => 'you can create a new application if you want',
        };
    }
}

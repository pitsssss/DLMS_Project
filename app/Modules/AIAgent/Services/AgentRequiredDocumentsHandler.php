<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentCatalogLocalizer;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use App\Modules\Applications\Services\ApplicationDocumentService;
use Illuminate\Support\Collection;

class AgentRequiredDocumentsHandler
{
    public function __construct(
        private readonly AgentApplicationNextStepService $nextStepService,
        private readonly ApplicationDocumentService $documents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(User $citizen, AIAgentSession $session, string $language = 'ar'): array
    {
        $resolution = $this->nextStepService->resolveTargetApplication($citizen, $session);

        if ($resolution === null) {
            return $this->basePayload($language, [
                'reply' => AgentTranslator::message('ai_agent.required_documents.no_applications'),
                'proposed_action' => null,
            ]);
        }

        if ($resolution instanceof Collection) {
            return $this->basePayload($language, [
                'reply' => AgentTranslator::message('ai_agent.required_documents.multiple_applications', [
                    'summary' => $this->formatApplicationList($resolution, $language),
                ]),
                'proposed_action' => null,
                'missing_slots' => ['application_choice'],
            ]);
        }

        try {
            $checklist = $this->documents->requiredChecklist($citizen, $resolution->id);
            $reply = $this->formatReply($resolution, $checklist);
        } catch (\Throwable) {
            $reply = AgentTranslator::message('ai_agent.required_documents.unavailable');
        }

        return $this->basePayload($language, [
            'reply' => $reply,
            'proposed_action' => [
                'name' => 'get_required_documents',
                'arguments' => [
                    'application_id' => $resolution->id,
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function replyFromActionResult(array $result): string
    {
        $checklist = $result['required_documents'] ?? [];
        if (! is_array($checklist) || $checklist === []) {
            return AgentTranslator::message('ai_agent.required_documents.unavailable');
        }

        $status = ApplicationStatus::tryFrom((string) ($result['status'] ?? '')) ?? ApplicationStatus::Draft;

        return $this->formatReplyText(
            $status,
            $this->documentNamesFromChecklist($checklist),
            $this->hasUploadedDocuments($checklist),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $checklist
     */
    public function formatReply(LicenseApplication $application, array $checklist): string
    {
        $status = $application->status instanceof ApplicationStatus
            ? $application->status
            : ApplicationStatus::tryFrom((string) $application->status) ?? ApplicationStatus::Draft;

        return $this->formatReplyText(
            $status,
            $this->documentNamesFromChecklist($checklist),
            $this->hasUploadedDocuments($checklist),
        );
    }

    /**
     * @param  list<string>  $documentNames
     */
    private function formatReplyText(
        ApplicationStatus $status,
        array $documentNames,
        bool $hasUploaded,
    ): string
    {
        if ($documentNames === []) {
            return AgentTranslator::message('ai_agent.required_documents.unavailable');
        }

        $documentsList = implode('، ', $documentNames);
        $reply = AgentTranslator::message('ai_agent.required_documents.list', [
            'documents' => $documentsList,
        ]);

        if ($this->isPastDocumentUploadStage($status)) {
            $reply .= ' '.AgentTranslator::message('ai_agent.required_documents.stage_completed_hint');
        } elseif ($hasUploaded) {
            $reply .= ' '.AgentTranslator::message('ai_agent.required_documents.already_uploaded_hint');
        } else {
            $reply .= ' يمكنك رفع هذه الوثائق من صفحة وثائق الطلب.';
        }

        return trim($reply);
    }

    private function isPastDocumentUploadStage(ApplicationStatus $status): bool
    {
        return in_array($status, [
            ApplicationStatus::DocumentsUnderReview,
            ApplicationStatus::PaymentPending,
            ApplicationStatus::PaymentCompleted,
            ApplicationStatus::AppointmentPending,
            ApplicationStatus::InTesting,
            ApplicationStatus::WaitingRetest,
            ApplicationStatus::Approved,
            ApplicationStatus::AdministrativeReview,
            ApplicationStatus::LicenseIssued,
            ApplicationStatus::Rejected,
            ApplicationStatus::Cancelled,
        ], true);
    }

    /**
     * @param  list<array<string, mixed>>  $checklist
     * @return list<string>
     */
    private function documentNamesFromChecklist(array $checklist): array
    {
        $names = [];
        foreach ($checklist as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = AgentCatalogLocalizer::documentFromItem($item);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  list<array<string, mixed>>  $checklist
     */
    private function hasUploadedDocuments(array $checklist): bool
    {
        foreach ($checklist as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! empty($item['latest_document'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, LicenseApplication>  $applications
     */
    private function formatApplicationList(Collection $applications, string $language): string
    {
        return $applications
            ->map(function (LicenseApplication $application) use ($language): string {
                $licenseLabel = AgentCatalogLocalizer::licenseType(
                    (string) ($application->licenseType?->code ?? ''),
                    null,
                    $language
                );
                $statusLabel = $language === 'en'
                    ? ApplicationStatusLabelMapper::labelEn($application->status)
                    : ApplicationStatusLabelMapper::labelAr($application->status);

                if ($language === 'en') {
                    return '- '.$application->application_number.' ('.$licenseLabel.'): '.$statusLabel;
                }

                return '- '.$application->application_number.' — رخصة قيادة '.$licenseLabel.' — '.$statusLabel;
            })
            ->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(string $language, array $overrides): array
    {
        return array_merge([
            'intent' => AgentIntent::GetRequiredDocuments->value,
            'confidence' => 0.93,
            'language' => $language,
            'missing_slots' => [],
            'requires_confirmation' => false,
            'execute_immediately' => true,
            'safety_status' => 'safe',
            'requires_human_support' => false,
        ], $overrides);
    }
}

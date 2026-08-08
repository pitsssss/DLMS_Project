<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentRejectionReason;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Models\LicenseApplication;
use App\Modules\AIAgent\Enums\AgentSessionStatus;
use App\Modules\AIAgent\Support\AgentCatalogLocalizer;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\Applications\Services\ApplicationDocumentService;
use App\Modules\AIAgent\Services\AgentApplicationActionPolicy;
use App\Modules\AIAgent\Services\AIAgentService;
use App\Modules\AIAgent\Models\AIAgentSession;
use Illuminate\Http\UploadedFile;

class AgentDocumentUploadService
{
    public function __construct(
        private readonly AIAgentService $agent,
        private readonly ApplicationDocumentService $documents,
        private readonly AgentApplicationActionPolicy $applicationPolicy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function upload(User $citizen, int $sessionId, int $applicationId, int $requiredDocumentId, UploadedFile $file): array
    {
        $session = $this->agent->getSessionForUser($citizen, $sessionId);
        if ($session->status === AgentSessionStatus::Closed) {
            throw new ApiException('This AI agent session is closed.', 422);
        }

        $application = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereKey($applicationId)
            ->first();

        if ($application === null) {
            throw new ApiException('messages.applications.not_found', 404);
        }

        // Upload uses the shared domain logic (ownership + business rules + file validation).
        $uploaded = $this->documents->upload($citizen, $applicationId, $requiredDocumentId, $file);

        $checklist = $this->documents->requiredChecklist($citizen, $applicationId);

        $requiredItems = array_values(array_filter(
            $checklist,
            static fn (array $item): bool => ($item['is_required'] ?? false) === true
        ));

        $missing = [];
        $rejected = [];
        $pendingReview = [];
        $completed = [];

        foreach ($requiredItems as $item) {
            $latest = $item['latest_document'] ?? null;
            $required = [
                'id' => $item['id'] ?? null,
                'code' => $item['code'] ?? null,
                'name' => $item['name'] ?? null,
            ];

            if ($latest === null) {
                if ($required['id'] !== null) {
                    $missing[] = $required;
                }
                continue;
            }

            $status = (string) ($latest['status'] ?? '');

            if ($status === DocumentStatus::Rejected->value) {
                $rejected[] = [
                    'required_document' => $required,
                    'document' => [
                        'id' => $latest['id'] ?? null,
                        'status' => $status,
                        'rejection' => $latest['rejection'] ?? null,
                    ],
                ];
                continue;
            }

            $completed[] = $required;

            if ($status === DocumentStatus::PendingReview->value) {
                $pendingReview[] = $required;
            }
        }

        $allRequiredUploaded = $missing === [] && $rejected === [];
        $blockReason = $this->applicationPolicy->blockReason($application, 'submit_documents_for_review');
        $canSubmitForReview = $allRequiredUploaded && $blockReason === null;

        $agentReply = $canSubmitForReview
            ? AgentTranslator::message('ai_agent.document_upload.complete_can_submit')
            : $this->buildUploadAgentReply($requiredItems, $missing, $rejected);

        $this->updateSessionContext($session, $applicationId, (int) $uploaded->id, $requiredDocumentId);

        return [
            'session_id' => $session->id,
            'application' => [
                'id' => $application->id,
                'status' => $application->status instanceof ApplicationStatus
                    ? $application->status->value
                    : (string) $application->status,
                'status_label' => ApplicationStatusLabelMapper::label($application->status),
            ],
            'document' => [
                'id' => $uploaded->id,
                'required_document_id' => $uploaded->required_document_id,
                'type_code' => $uploaded->requiredDocument?->code,
                'type_label' => AgentCatalogLocalizer::document(
                    (string) ($uploaded->requiredDocument?->code ?? ''),
                    $uploaded->requiredDocument?->name
                ),
                'status' => $uploaded->status->value,
                'status_label' => $this->documentStatusLabel($uploaded->status),
                'rejection' => $uploaded->status === DocumentStatus::Rejected ? [
                    'code' => $uploaded->rejection_reason_code,
                    'label' => DocumentRejectionReason::tryFrom((string) $uploaded->rejection_reason_code)?->label()
                        ?? (string) $uploaded->rejection_reason_code,
                    'details' => $uploaded->rejection_details,
                ] : null,
            ],
            'checklist' => [
                'completed' => $completed,
                'missing' => $missing,
                'rejected' => $rejected,
                'pending_review' => $pendingReview,
                'all_required_uploaded' => $allRequiredUploaded,
                'can_submit_for_review' => $canSubmitForReview,
            ],
            'agent_reply' => $agentReply,
        ];
    }

    private function updateSessionContext(
        AIAgentSession $session,
        int $applicationId,
        int $uploadedDocumentId,
        int $requiredDocumentId
    ): void {
        $context = $session->context ?? [];
        $context['last_application_id'] = $applicationId;
        $context['last_uploaded_document_id'] = $uploadedDocumentId;
        $context['last_required_document_id'] = $requiredDocumentId;
        $session->context = $context;
        $session->last_message_at = now();
        $session->save();
    }

    /**
     * @param  list<array<string, mixed>>  $requiredItems
     * @param  list<array<string, mixed>>  $missing
     * @param  list<array<string, mixed>>  $rejected
     */
    private function buildUploadAgentReply(array $requiredItems, array $missing, array $rejected): string
    {
        if ($missing !== []) {
            $missingNames = implode(
                AgentTranslator::getLocale() === 'en' ? ', ' : '، ',
                array_values(array_map(
                    static fn (array $d): string => AgentCatalogLocalizer::documentFromItem($d),
                    $missing
                ))
            );

            return AgentTranslator::message('ai_agent.document_upload.missing_remaining', [
                'names' => $missingNames,
            ]);
        }

        if ($rejected !== []) {
            $rejectedNames = implode(
                AgentTranslator::getLocale() === 'en' ? ', ' : '، ',
                array_values(array_map(
                    static function (array $item): string {
                        $rd = is_array($item['required_document'] ?? null) ? $item['required_document'] : [];

                        return AgentCatalogLocalizer::documentFromItem($rd);
                    },
                    $rejected
                ))
            );

            return AgentTranslator::message('ai_agent.document_upload.rejected_remaining', [
                'names' => $rejectedNames,
            ]);
        }

        return AgentTranslator::message('ai_agent.document_upload.success');
    }

    private function documentStatusLabel(DocumentStatus $status): string
    {
        return match ($status) {
            DocumentStatus::PendingReview => AgentTranslator::message('ai_agent.document_status.pending_review'),
            DocumentStatus::Approved => AgentTranslator::message('ai_agent.document_status.approved'),
            DocumentStatus::Rejected => AgentTranslator::message('ai_agent.document_status.rejected'),
        };
    }
}


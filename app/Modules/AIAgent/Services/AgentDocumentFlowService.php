<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Enums\DocumentStatus;
use App\Exceptions\ApiException;
use App\Models\LicenseApplication;
use App\Models\RequiredDocument;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentActionStatus;
use App\Modules\AIAgent\Enums\AgentMessageRole;
use App\Modules\AIAgent\Enums\AgentSessionStatus;
use App\Modules\AIAgent\Enums\DocumentFlowState;
use App\Modules\AIAgent\Models\AIAgentAction;
use App\Modules\AIAgent\Models\AIAgentMessage;
use App\Modules\AIAgent\Models\AIAgentSession;
use App\Modules\AIAgent\Support\AgentDocumentFlowPhraseMatcher;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use App\Modules\Applications\Services\ApplicationDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AgentDocumentFlowService
{
    public function __construct(
        private readonly ApplicationDocumentService $documents,
        private readonly AgentSelectionTokenService $selectionTokens,
        private readonly AgentUploadTokenService $uploadTokens,
        private readonly AgentApplicationActionPolicy $applicationPolicy,
        private readonly AgentActionExecutor $actionExecutor,
    ) {}

    public function getFlow(AIAgentSession $session): array
    {
        $context = $session->context ?? [];
        $flow = $context['document_flow'] ?? [];

        return is_array($flow) ? $flow : [];
    }

    public function getState(AIAgentSession $session): DocumentFlowState
    {
        return DocumentFlowState::tryFrom((string) ($this->getFlow($session)['state'] ?? ''))
            ?? DocumentFlowState::Idle;
    }

    public function shouldHandleTextDecision(AIAgentSession $session, string $message): bool
    {
        if (! $this->getState($session)->allowsUploadOfferDecision()) {
            return false;
        }

        return AgentDocumentFlowPhraseMatcher::isAgentUploadConsent($message)
            || AgentDocumentFlowPhraseMatcher::isManualUploadChoice($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleTextDecision(User $citizen, AIAgentSession $session, string $message): array
    {
        if (AgentDocumentFlowPhraseMatcher::isManualUploadChoice($message)) {
            return $this->chooseManualUpload($citizen, $session);
        }

        if (AgentDocumentFlowPhraseMatcher::isAgentUploadConsent($message)) {
            return $this->chooseAgentUpload($citizen, $session);
        }

        throw new ApiException(
            AgentTranslator::message('ai_agent.document_flow.selection_required'),
            422,
            [],
            [],
            'DOCUMENT_SELECTION_REQUIRED'
        );
    }

    /**
     * Entry point when the citizen asks about required documents.
     *
     * @return array<string, mixed>
     */
    public function startRequiredDocumentsFlow(User $citizen, AIAgentSession $session): array
    {
        $this->assertSessionActive($session);

        $eligible = $this->eligibleApplications($citizen);
        if ($eligible->isEmpty()) {
            $this->saveFlow($session, [
                'state' => DocumentFlowState::Idle->value,
            ]);
            $session->current_intent = 'get_required_documents';
            $session->save();

            return $this->response(
                $session,
                'document_flow_error',
                AgentTranslator::message('ai_agent.document_flow.no_eligible_application'),
                [],
                [
                    'executed_action' => null,
                    'pending_action' => null,
                ]
            );
        }

        if ($eligible->count() > 1) {
            $this->saveFlow($session, [
                'state' => DocumentFlowState::ApplicationSelection->value,
                'mode' => null,
                'auto_submit_on_completion' => false,
            ]);
            $session->current_intent = 'get_required_documents';
            $session->save();

            return $this->response(
                $session,
                'application_selection_required',
                AgentTranslator::message('ai_agent.document_flow.multiple_applications'),
                [
                    'applications' => $eligible->map(function (LicenseApplication $application) use ($citizen, $session): array {
                        $licenseCode = (string) ($application->licenseType?->code ?? '');
                        $licenseLabel = AgentTranslator::getLocale() === 'en'
                            ? LicenseTypeSlotExtractor::labelEn($licenseCode)
                            : LicenseTypeSlotExtractor::labelAr($licenseCode);
                        $serviceLabel = (string) ($application->serviceType?->name
                            ?? AgentTranslator::message('ai_agent.document_flow.service_fallback'));

                        return [
                            'label' => AgentTranslator::message('ai_agent.document_flow.application_option', [
                                'service' => $serviceLabel,
                                'license' => $licenseLabel,
                                'id' => $application->id,
                            ]),
                            'action' => 'select_application',
                            'selection_token' => $this->selectionTokens->issue(
                                $citizen,
                                $session,
                                AgentSelectionTokenService::PURPOSE_APPLICATION,
                                (int) $application->id
                            ),
                        ];
                    })->values()->all(),
                ],
                [
                    'executed_action' => null,
                    'pending_action' => null,
                ]
            );
        }

        /** @var LicenseApplication $application */
        $application = $eligible->first();

        return $this->enterUploadOffer($citizen, $session, $application);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleInteraction(User $citizen, AIAgentSession $session, string $action, ?string $selectionToken = null): array
    {
        $this->assertSessionActive($session);

        return match ($action) {
            'show_required_documents' => $this->startRequiredDocumentsFlow($citizen, $session),
            'choose_agent_document_upload' => $this->chooseAgentUpload($citizen, $session),
            'choose_manual_document_upload' => $this->chooseManualUpload($citizen, $session),
            'select_application' => $this->selectApplication($citizen, $session, (string) $selectionToken),
            'select_required_document' => $this->selectRequiredDocument($citizen, $session, (string) $selectionToken),
            'cancel_document_upload' => $this->cancelFlow($session),
            default => throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.unknown_interaction'),
                422,
                ['action' => ['Unsupported interaction action.']],
                [],
                'DOCUMENT_FLOW_ERROR'
            ),
        };
    }

    /**
     * Conversational upload using upload_token (official Flutter path).
     *
     * @param  list<UploadedFile>  $files
     * @return array<string, mixed>
     */
    public function uploadWithToken(
        User $citizen,
        AIAgentSession $session,
        string $plainToken,
        array $files,
        ?int $legacyApplicationId = null,
        ?int $legacyRequiredDocumentId = null,
    ): array {
        $this->assertSessionActive($session);

        $label = (string) ($this->getFlow($session)['required_document_label']
            ?? AgentTranslator::message('ai_agent.document_flow.document_fallback'));
        $fileCount = count($files);

        if ($fileCount === 0) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.file_required'),
                422,
                [],
                [],
                'DOCUMENT_FILE_REQUIRED'
            );
        }

        if ($fileCount > 1) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.multiple_files_with_label', ['label' => $label]),
                422,
                [],
                [],
                'EXACTLY_ONE_DOCUMENT_FILE_REQUIRED',
                [
                    'selected_document' => ['label' => $label],
                    'received_files_count' => $fileCount,
                    'maximum_files' => 1,
                    'upload_token_still_valid' => true,
                    'message_type' => 'multiple_files_rejected',
                    'reply' => AgentTranslator::message('ai_agent.document_flow.multiple_files_reply', ['label' => $label]),
                ]
            );
        }

        /** @var UploadedFile $file */
        $file = $files[0];

        $binding = DB::transaction(function () use ($session, $plainToken, $legacyApplicationId, $legacyRequiredDocumentId) {
            $locked = AIAgentSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $flow = $this->getFlow($locked);
            $binding = $this->uploadTokens->assertActiveToken($flow, $plainToken, $locked);

            if ($legacyApplicationId !== null && $legacyApplicationId !== $binding['application_id']) {
                throw new ApiException(
                    AgentTranslator::message('ai_agent.document_flow.upload_token_app_mismatch'),
                    422,
                    [],
                    [],
                    'INVALID_UPLOAD_TOKEN'
                );
            }

            if ($legacyRequiredDocumentId !== null && $legacyRequiredDocumentId !== $binding['required_document_id']) {
                throw new ApiException(
                    AgentTranslator::message('ai_agent.document_flow.upload_token_doc_mismatch'),
                    422,
                    [],
                    [],
                    'INVALID_UPLOAD_TOKEN'
                );
            }

            $flow['state'] = DocumentFlowState::UploadProcessing->value;
            $flow['upload_token_status'] = 'processing';
            $this->writeFlow($locked, $flow);

            return $binding;
        });

        try {
            $uploaded = $this->documents->upload(
                $citizen,
                $binding['application_id'],
                $binding['required_document_id'],
                $file
            );
        } catch (\Throwable $e) {
            DB::transaction(function () use ($session): void {
                $locked = AIAgentSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                $flow = $this->getFlow($locked);
                if ((string) ($flow['upload_token_status'] ?? '') === 'processing') {
                    $flow['upload_token_status'] = 'active';
                    $flow['state'] = DocumentFlowState::AwaitingFile->value;
                    $this->writeFlow($locked, $flow);
                }
            });

            if ($e instanceof ApiException) {
                throw $e;
            }

            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.upload_failed'),
                422,
                [],
                [],
                'INVALID_DOCUMENT_FILE'
            );
        }

        return DB::transaction(function () use ($citizen, $session, $binding, $uploaded) {
            $locked = AIAgentSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $flow = $this->getFlow($locked);

            if ((string) ($flow['upload_token_status'] ?? '') !== 'processing') {
                throw new ApiException(
                    AgentTranslator::message('ai_agent.document_flow.upload_state_conflict'),
                    422,
                    [],
                    [],
                    'APPLICATION_STATE_CHANGED'
                );
            }

            $flow['upload_token_status'] = 'consumed';
            $flow['upload_token_hash'] = null;
            $flow['upload_token_expires_at'] = null;
            $flow['required_document_id'] = null;
            $flow['required_document_label'] = null;
            $this->writeFlow($locked, $flow);

            $context = $locked->context ?? [];
            $context['last_application_id'] = $binding['application_id'];
            $context['last_uploaded_document_id'] = (int) $uploaded->id;
            $context['last_required_document_id'] = $binding['required_document_id'];
            $locked->context = $context;
            $locked->last_message_at = now();
            $locked->save();

            return $this->afterSuccessfulUpload($citizen, $locked, $binding['application_id'], $uploaded);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function afterSuccessfulUpload(
        User $citizen,
        AIAgentSession $session,
        int $applicationId,
        $uploaded,
    ): array {
        $application = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereKey($applicationId)
            ->firstOrFail();

        $checklistMeta = $this->buildChecklistMeta($citizen, $applicationId);
        $label = (string) ($uploaded->requiredDocument?->name
            ?? AgentTranslator::message('ai_agent.document_flow.document_fallback'));

        if (! $checklistMeta['all_required_uploaded']) {
            $remaining = $this->buildUploadableDocumentButtons($citizen, $session, $application);
            $separator = AgentTranslator::getLocale() === 'en' ? ' and ' : ' و';
            $remainingNames = implode($separator, array_map(
                static fn (array $item): string => (string) ($item['label'] ?? ''),
                $remaining
            ));

            $this->saveFlow($session, array_merge($this->getFlow($session), [
                'state' => DocumentFlowState::DocumentSelectionAfterUpload->value,
                'application_id' => $applicationId,
            ]));

            $reply = AgentTranslator::message('ai_agent.document_flow.uploaded_remaining', [
                'label' => $label,
                'remaining' => $remainingNames,
            ]);

            $this->storeAssistantMessage($session, $reply, [
                'message_type' => 'document_uploaded',
            ]);

            return $this->response($session, 'document_uploaded', $reply, [
                'remaining_documents' => $remaining,
            ], [
                'application' => $this->applicationPayload($application),
                'uploaded_document' => [
                    'id' => $uploaded->id,
                    'label' => $label,
                    'status' => $uploaded->status->value,
                ],
            ]);
        }

        $flow = $this->getFlow($session);
        $autoSubmit = (bool) ($flow['auto_submit_on_completion'] ?? false)
            && ! empty($flow['submission_consent_at'])
            && ($flow['mode'] ?? null) === 'agent'
            && $checklistMeta['can_submit_for_review'];

        if (! $autoSubmit) {
            $this->saveFlow($session, array_merge($flow, [
                'state' => DocumentFlowState::DocumentSelectionAfterUpload->value,
                'application_id' => $applicationId,
            ]));

            $reply = AgentTranslator::message('ai_agent.document_flow.uploaded_complete', [
                'label' => $label,
            ]);
            $this->storeAssistantMessage($session, $reply, ['message_type' => 'document_uploaded']);

            return $this->response($session, 'document_uploaded', $reply, [
                'remaining_documents' => [],
                'all_required_uploaded' => true,
                'submitted_for_review' => false,
            ], [
                'application' => $this->applicationPayload($application),
                'uploaded_document' => [
                    'id' => $uploaded->id,
                    'label' => $label,
                    'status' => $uploaded->status->value,
                ],
            ]);
        }

        return $this->autoSubmitForReview($citizen, $session, $application, $uploaded, $label);
    }

    /**
     * @return array<string, mixed>
     */
    private function autoSubmitForReview(
        User $citizen,
        AIAgentSession $session,
        LicenseApplication $application,
        $uploaded,
        string $uploadedLabel,
    ): array {
        $flow = $this->getFlow($session);

        if (($flow['state'] ?? null) === DocumentFlowState::Completed->value
            && ($flow['submitted_for_review'] ?? false) === true) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.already_submitted'),
                422,
                [],
                [],
                'DOCUMENT_SUBMISSION_FAILED'
            );
        }

        $this->saveFlow($session, array_merge($flow, [
            'state' => DocumentFlowState::SubmittingForReview->value,
        ]));

        try {
            $action = AIAgentAction::query()->create([
                'session_id' => $session->id,
                'user_id' => $citizen->id,
                'action_name' => 'submit_documents_for_review',
                'arguments' => [
                    'application_id' => $application->id,
                    'confirmation_source' => 'upfront_document_flow_consent',
                    'submission_consent_source' => $flow['submission_consent_source'] ?? 'agent_upload_offer',
                    'submission_consent_at' => $flow['submission_consent_at'] ?? null,
                ],
                'status' => AgentActionStatus::Confirmed,
                'requires_confirmation' => false,
                'confirmation_message' => AgentTranslator::message('ai_agent.document_flow.consent_confirmation_message'),
                'confirmed_at' => now(),
            ]);

            $result = $this->actionExecutor->execute($citizen, $action);
            $action->status = AgentActionStatus::Executed;
            $action->executed_at = now();
            $action->result = array_merge($result, [
                'confirmation_source' => 'upfront_document_flow_consent',
            ]);
            $action->save();

            $application->refresh();

            $this->saveFlow($session, array_merge($this->getFlow($session), [
                'state' => DocumentFlowState::Completed->value,
                'submitted_for_review' => true,
                'mode' => 'agent',
                'application_id' => $application->id,
                'upload_token_hash' => null,
                'upload_token_status' => 'consumed',
            ]));

            $reply = AgentTranslator::message('ai_agent.document_flow.submitted_for_review');

            $this->storeAssistantMessage($session, $reply, [
                'message_type' => 'documents_submitted_for_review',
                'action_id' => $action->id,
            ]);

            return $this->response($session, 'documents_submitted_for_review', $reply, [
                'submitted_for_review' => true,
                'review_queue' => 'shared_document_review_queue',
                'next_step' => 'wait_for_document_review',
                'navigation_target' => [
                    'screen' => 'application_details',
                    'params' => ['application_id' => $application->id],
                ],
            ], [
                'application' => $this->applicationPayload($application),
                'uploaded_document' => [
                    'id' => $uploaded->id,
                    'label' => $uploadedLabel,
                    'status' => $uploaded->status->value,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->saveFlow($session, array_merge($this->getFlow($session), [
                'state' => DocumentFlowState::DocumentSelectionAfterUpload->value,
                'submitted_for_review' => false,
            ]));

            $reply = AgentTranslator::message('ai_agent.document_flow.submission_failed_after_upload');
            $this->storeAssistantMessage($session, $reply, [
                'message_type' => 'documents_uploaded_submission_failed',
            ]);

            return $this->response($session, 'documents_uploaded_submission_failed', $reply, [
                'all_required_uploaded' => true,
                'submitted_for_review' => false,
            ], [
                'application' => $this->applicationPayload($application->fresh() ?? $application),
                'uploaded_document' => [
                    'id' => $uploaded->id,
                    'label' => $uploadedLabel,
                    'status' => $uploaded->status->value,
                ],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function enterUploadOffer(User $citizen, AIAgentSession $session, LicenseApplication $application): array
    {
        $application->loadMissing(['licenseType', 'serviceType']);
        $checklist = $this->documents->requiredChecklist($citizen, $application->id);
        $names = $this->requiredDocumentNames($checklist);
        $documentsList = $this->formatDocumentNames($names);

        $this->saveFlow($session, [
            'state' => DocumentFlowState::DocumentUploadOffer->value,
            'mode' => null,
            'application_id' => $application->id,
            'auto_submit_on_completion' => false,
            'submission_consent_at' => null,
            'submission_consent_source' => null,
            'upload_token_hash' => null,
            'upload_token_status' => null,
            'upload_token_expires_at' => null,
            'required_document_id' => null,
            'required_document_label' => null,
            'submitted_for_review' => false,
        ]);

        $context = $session->context ?? [];
        $context['last_application_id'] = $application->id;
        $context['active_application_id'] = $application->id;
        $session->context = $context;
        $session->current_intent = 'get_required_documents';
        $session->last_message_at = now();
        $session->save();

        $action = AIAgentAction::query()->create([
            'session_id' => $session->id,
            'user_id' => $citizen->id,
            'action_name' => 'get_required_documents',
            'arguments' => ['application_id' => $application->id],
            'status' => AgentActionStatus::Executed,
            'requires_confirmation' => false,
            'confirmation_message' => null,
            'result' => [
                'application_id' => $application->id,
                'application_number' => $application->application_number,
                'status' => $application->status instanceof ApplicationStatus
                    ? $application->status->value
                    : (string) $application->status,
                'required_documents' => $checklist,
            ],
            'executed_at' => now(),
        ]);

        $reply = AgentTranslator::message('ai_agent.document_flow.upload_offer', [
            'documents' => $documentsList,
        ]);

        $this->storeAssistantMessage($session, $reply, [
            'message_type' => 'document_upload_offer',
            'action_id' => $action->id,
        ]);

        return $this->response($session, 'document_upload_offer', $reply, [
            'documents' => $this->buildDocumentSummaries($checklist),
            'buttons' => [
                [
                    'label' => AgentTranslator::message('ai_agent.document_flow.button_agent_upload'),
                    'action' => 'choose_agent_document_upload',
                ],
                [
                    'label' => AgentTranslator::message('ai_agent.document_flow.button_manual_upload'),
                    'action' => 'choose_manual_document_upload',
                ],
            ],
        ], [
            'application' => $this->applicationPayload($application),
            'executed_action' => [
                'id' => $action->id,
                'name' => $action->action_name,
                'arguments' => $action->arguments,
                'requires_confirmation' => false,
                'status' => $action->status->value,
            ],
            'result' => $action->result,
            'action_confirmed' => true,
            'action_cancelled' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chooseAgentUpload(User $citizen, AIAgentSession $session): array
    {
        $state = $this->getState($session);
        if (! $state->allowsUploadOfferDecision()) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.cannot_confirm_agent_upload'),
                422,
                [],
                [],
                'DOCUMENT_SELECTION_REQUIRED'
            );
        }

        $application = $this->resolveBoundEligibleApplication($citizen, $session);

        $this->saveFlow($session, array_merge($this->getFlow($session), [
            'state' => DocumentFlowState::DocumentSelection->value,
            'mode' => 'agent',
            'application_id' => $application->id,
            'auto_submit_on_completion' => true,
            'submission_consent_at' => now()->toIso8601String(),
            'submission_consent_source' => 'agent_upload_offer',
            'submitted_for_review' => false,
        ]));

        $documents = $this->buildUploadableDocumentButtons($citizen, $session, $application);
        if ($documents === []) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.no_documents_to_upload'),
                422,
                [],
                [],
                'DOCUMENT_SELECTION_REQUIRED'
            );
        }

        $reply = AgentTranslator::message('ai_agent.document_flow.choose_document');
        $this->storeAssistantMessage($session, $reply, ['message_type' => 'required_document_selection']);

        return $this->response($session, 'required_document_selection', $reply, [
            'documents' => $documents,
        ], [
            'application' => $this->applicationPayload($application),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chooseManualUpload(User $citizen, AIAgentSession $session): array
    {
        $state = $this->getState($session);
        if (! $state->allowsUploadOfferDecision()) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.cannot_choose_manual'),
                422,
                [],
                [],
                'DOCUMENT_SELECTION_REQUIRED'
            );
        }

        $application = $this->resolveBoundEligibleApplication($citizen, $session);

        $this->saveFlow($session, [
            'state' => DocumentFlowState::ManualUploadSelected->value,
            'mode' => 'manual',
            'application_id' => $application->id,
            'auto_submit_on_completion' => false,
            'submission_consent_at' => null,
            'submission_consent_source' => null,
            'upload_token_hash' => null,
            'upload_token_status' => null,
            'upload_token_expires_at' => null,
            'required_document_id' => null,
            'required_document_label' => null,
            'submitted_for_review' => false,
        ]);

        $reply = AgentTranslator::message('ai_agent.document_flow.manual_guidance');
        $this->storeAssistantMessage($session, $reply, ['message_type' => 'manual_document_upload_guidance']);

        return $this->response($session, 'manual_document_upload_guidance', $reply, [
            'navigation_target' => [
                'screen' => 'application_documents',
                'params' => ['application_id' => $application->id],
            ],
            'button_label' => AgentTranslator::message('ai_agent.document_flow.button_go_to_documents'),
        ], [
            'application' => $this->applicationPayload($application),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function selectApplication(User $citizen, AIAgentSession $session, string $selectionToken): array
    {
        if ($this->getState($session) !== DocumentFlowState::ApplicationSelection) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.application_selection_unexpected'),
                422,
                [],
                [],
                'APPLICATION_SELECTION_REQUIRED'
            );
        }

        $payload = $this->selectionTokens->verify(
            $selectionToken,
            $citizen,
            $session,
            AgentSelectionTokenService::PURPOSE_APPLICATION
        );

        $application = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereKey($payload['aid'])
            ->with(['licenseType', 'serviceType'])
            ->first();

        if ($application === null || ! $this->isEligibleForUpload($application)) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.application_not_eligible'),
                422,
                [],
                [],
                'APPLICATION_NOT_ELIGIBLE_FOR_DOCUMENT_UPLOAD'
            );
        }

        return $this->enterUploadOffer($citizen, $session, $application);
    }

    /**
     * @return array<string, mixed>
     */
    private function selectRequiredDocument(User $citizen, AIAgentSession $session, string $selectionToken): array
    {
        if (! $this->getState($session)->allowsDocumentSelection()) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.document_selection_unexpected'),
                422,
                [],
                [],
                'DOCUMENT_SELECTION_REQUIRED'
            );
        }

        $payload = $this->selectionTokens->verify(
            $selectionToken,
            $citizen,
            $session,
            AgentSelectionTokenService::PURPOSE_REQUIRED_DOCUMENT
        );

        $application = $this->resolveBoundEligibleApplication($citizen, $session);
        if ((int) $application->id !== (int) $payload['aid']) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.selection_token_app_mismatch'),
                422,
                [],
                [],
                'INVALID_SELECTION_TOKEN'
            );
        }

        $requiredDocumentId = (int) ($payload['rid'] ?? 0);
        if ($requiredDocumentId <= 0) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.selection_token_invalid'),
                422,
                [],
                [],
                'INVALID_SELECTION_TOKEN'
            );
        }

        $required = RequiredDocument::query()
            ->whereKey($requiredDocumentId)
            ->where('is_active', true)
            ->first();

        if ($required === null) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.required_document_missing'),
                422,
                [],
                [],
                'REQUIRED_DOCUMENT_NOT_APPLICABLE'
            );
        }

        $checklist = $this->documents->requiredChecklist($citizen, $application->id);
        $item = collect($checklist)->firstWhere('id', $required->id);
        if ($item === null) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.document_not_required'),
                422,
                [],
                [],
                'REQUIRED_DOCUMENT_NOT_APPLICABLE'
            );
        }

        $latest = is_array($item['latest_document'] ?? null) ? $item['latest_document'] : null;
        if (($latest['status'] ?? null) === DocumentStatus::Approved->value) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.cannot_replace_approved'),
                422,
                [],
                [],
                'DOCUMENT_ALREADY_APPROVED'
            );
        }

        $plainToken = $this->uploadTokens->issuePlainToken();
        $ttl = (int) config('ai.agent.document_upload_token_ttl_seconds', 600);
        $extensions = $this->normalizeExtensions($required->allowed_extensions);
        $maxSizeKb = (int) ($required->max_size_kb ?: 4096);

        $this->saveFlow($session, array_merge($this->getFlow($session), [
            'state' => DocumentFlowState::AwaitingFile->value,
            'mode' => 'agent',
            'application_id' => $application->id,
            'required_document_id' => $required->id,
            'required_document_label' => $required->name,
            'upload_token_hash' => $this->uploadTokens->hash($plainToken),
            'upload_token_status' => 'active',
            'upload_token_expires_at' => now()->addSeconds($ttl)->toIso8601String(),
        ]));

        $reply = AgentTranslator::message('ai_agent.document_flow.awaiting_file', [
            'label' => $required->name,
        ]);
        $this->storeAssistantMessage($session, $reply, ['message_type' => 'file_upload_required']);

        return $this->response($session, 'file_upload_required', $reply, [
            'document' => [
                'label' => $required->name,
            ],
            'upload_token' => $plainToken,
            'allowed_extensions' => $extensions,
            'max_size_kb' => $maxSizeKb,
            'maximum_files' => 1,
        ], [
            'application' => $this->applicationPayload($application),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function cancelFlow(AIAgentSession $session): array
    {
        $this->saveFlow($session, [
            'state' => DocumentFlowState::Idle->value,
            'mode' => null,
            'auto_submit_on_completion' => false,
            'upload_token_hash' => null,
            'upload_token_status' => null,
            'upload_token_expires_at' => null,
            'required_document_id' => null,
            'required_document_label' => null,
        ]);

        $reply = AgentTranslator::message('ai_agent.document_flow.cancelled');
        $this->storeAssistantMessage($session, $reply, ['message_type' => 'document_flow_error']);

        return $this->response($session, 'document_flow_error', $reply, []);
    }

    /**
     * @return Collection<int, LicenseApplication>
     */
    private function eligibleApplications(User $citizen): Collection
    {
        return LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereIn('status', [
                ApplicationStatus::Draft->value,
                ApplicationStatus::DocumentsRejected->value,
            ])
            ->with(['licenseType', 'serviceType'])
            ->orderByDesc('id')
            ->get();
    }

    private function isEligibleForUpload(LicenseApplication $application): bool
    {
        $status = $application->status instanceof ApplicationStatus
            ? $application->status
            : ApplicationStatus::tryFrom((string) $application->status);

        return in_array($status, [ApplicationStatus::Draft, ApplicationStatus::DocumentsRejected], true);
    }

    private function resolveBoundEligibleApplication(User $citizen, AIAgentSession $session): LicenseApplication
    {
        $flow = $this->getFlow($session);
        $context = $session->context ?? [];
        $applicationId = (int) ($flow['application_id']
            ?? $context['active_application_id']
            ?? $context['last_application_id']
            ?? 0);

        if ($applicationId <= 0) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.choose_application_first'),
                422,
                [],
                [],
                'APPLICATION_SELECTION_REQUIRED'
            );
        }

        $application = LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereKey($applicationId)
            ->with(['licenseType', 'serviceType'])
            ->first();

        if ($application === null || ! $this->isEligibleForUpload($application)) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.session_application_not_eligible'),
                422,
                [],
                [],
                'APPLICATION_NOT_ELIGIBLE_FOR_DOCUMENT_UPLOAD'
            );
        }

        return $application;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildUploadableDocumentButtons(
        User $citizen,
        AIAgentSession $session,
        LicenseApplication $application,
    ): array {
        $checklist = $this->documents->requiredChecklist($citizen, $application->id);
        $buttons = [];

        foreach ($checklist as $item) {
            if (! ($item['is_required'] ?? false)) {
                continue;
            }

            $latest = is_array($item['latest_document'] ?? null) ? $item['latest_document'] : null;
            $status = (string) ($latest['status'] ?? '');

            if ($status === DocumentStatus::Approved->value) {
                continue;
            }

            if ($latest !== null && $status === DocumentStatus::PendingReview->value) {
                continue;
            }

            $buttonStatus = $latest === null ? 'missing' : (
                $status === DocumentStatus::Rejected->value ? 'rejected' : 'missing'
            );

            $entry = [
                'label' => (string) ($item['name'] ?? ''),
                'status' => $buttonStatus,
                'action' => 'select_required_document',
                'selection_token' => $this->selectionTokens->issue(
                    $citizen,
                    $session,
                    AgentSelectionTokenService::PURPOSE_REQUIRED_DOCUMENT,
                    (int) $application->id,
                    (int) $item['id']
                ),
            ];

            if ($buttonStatus === 'rejected') {
                $entry['rejection_reason'] = (string) (
                    $latest['rejection']['details']
                    ?? $latest['rejection']['label']
                    ?? $latest['rejection_reason']
                    ?? AgentTranslator::message('ai_agent.document_flow.reupload_hint')
                );
            }

            $buttons[] = $entry;
        }

        return $buttons;
    }

    /**
     * @param  list<array<string, mixed>>  $checklist
     * @return list<array<string, mixed>>
     */
    private function buildDocumentSummaries(array $checklist): array
    {
        $items = [];
        foreach ($checklist as $item) {
            if (! ($item['is_required'] ?? false)) {
                continue;
            }
            $latest = is_array($item['latest_document'] ?? null) ? $item['latest_document'] : null;
            $status = $latest === null
                ? 'missing'
                : (string) ($latest['status'] ?? 'missing');

            $items[] = [
                'label' => (string) ($item['name'] ?? ''),
                'status' => $status === DocumentStatus::Rejected->value ? 'rejected' : (
                    $status === DocumentStatus::Approved->value ? 'approved' : (
                        $status === DocumentStatus::PendingReview->value ? 'pending_review' : 'missing'
                    )
                ),
            ];
        }

        return $items;
    }

    /**
     * @return array{all_required_uploaded: bool, can_submit_for_review: bool}
     */
    private function buildChecklistMeta(User $citizen, int $applicationId): array
    {
        $application = LicenseApplication::query()->whereKey($applicationId)->firstOrFail();
        $checklist = $this->documents->requiredChecklist($citizen, $applicationId);

        $missing = false;
        $rejected = false;

        foreach ($checklist as $item) {
            if (! ($item['is_required'] ?? false)) {
                continue;
            }
            $latest = is_array($item['latest_document'] ?? null) ? $item['latest_document'] : null;
            if ($latest === null) {
                $missing = true;
                continue;
            }
            if (($latest['status'] ?? '') === DocumentStatus::Rejected->value) {
                $rejected = true;
            }
        }

        $allRequiredUploaded = ! $missing && ! $rejected;
        $blockReason = $this->applicationPolicy->blockReason($application, 'submit_documents_for_review');

        return [
            'all_required_uploaded' => $allRequiredUploaded,
            'can_submit_for_review' => $allRequiredUploaded && $blockReason === null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $checklist
     * @return list<string>
     */
    private function requiredDocumentNames(array $checklist): array
    {
        $names = [];
        foreach ($checklist as $item) {
            if (! ($item['is_required'] ?? false)) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  list<string>  $names
     */
    private function formatDocumentNames(array $names): string
    {
        if ($names === []) {
            return AgentTranslator::message('ai_agent.document_flow.names_unspecified');
        }

        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);
        $isEn = AgentTranslator::getLocale() === 'en';

        return $isEn
            ? implode(', ', $names).', and '.$last
            : implode('، ', $names).'، و'.$last;
    }

    /**
     * @param  mixed  $extensions
     * @return list<string>
     */
    private function normalizeExtensions(mixed $extensions): array
    {
        if (! is_array($extensions) || $extensions === []) {
            return ['pdf', 'jpg', 'jpeg', 'png'];
        }

        return array_values(array_map(
            static fn ($ext): string => strtolower(ltrim((string) $ext, '.')),
            $extensions
        ));
    }

    /**
     * @return array{id: int, status: string, status_label: string}
     */
    private function applicationPayload(LicenseApplication $application): array
    {
        $status = $application->status instanceof ApplicationStatus
            ? $application->status->value
            : (string) $application->status;

        return [
            'id' => $application->id,
            'status' => $status,
            'status_label' => ApplicationStatusLabelMapper::label($application->status),
        ];
    }

    /**
     * @param  array<string, mixed>  $uiPayload
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function response(
        AIAgentSession $session,
        string $messageType,
        string $reply,
        array $uiPayload,
        array $extra = [],
    ): array {
        return array_merge([
            'session_id' => $session->id,
            'message_type' => $messageType,
            'reply' => $reply,
            'ui_payload' => $uiPayload,
            'intent' => 'get_required_documents',
            'confidence' => 1.0,
            'missing_slots' => [],
            'requires_confirmation' => false,
            'pending_action' => null,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $flow
     */
    private function saveFlow(AIAgentSession $session, array $flow): void
    {
        $this->writeFlow($session, $flow);
    }

    /**
     * @param  array<string, mixed>  $flow
     */
    private function writeFlow(AIAgentSession $session, array $flow): void
    {
        $context = $session->context ?? [];
        $context['document_flow'] = $flow;
        if (isset($flow['application_id'])) {
            $context['active_application_id'] = $flow['application_id'];
            $context['last_application_id'] = $flow['application_id'];
        }
        $session->context = $context;
        $session->last_message_at = now();
        $session->save();
    }

    private function storeAssistantMessage(AIAgentSession $session, string $content, array $metadata = []): void
    {
        AIAgentMessage::query()->create([
            'session_id' => $session->id,
            'role' => AgentMessageRole::Assistant,
            'content' => $content,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function assertSessionActive(AIAgentSession $session): void
    {
        if ($session->status === AgentSessionStatus::Closed) {
            throw new ApiException(
                AgentTranslator::message('ai_agent.document_flow.session_closed'),
                422,
                [],
                [],
                'AI_AGENT_SESSION_CLOSED'
            );
        }
    }
}

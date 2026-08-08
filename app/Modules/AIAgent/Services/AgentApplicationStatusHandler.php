<?php

namespace App\Modules\AIAgent\Services;

use App\Enums\ApplicationStatus;
use App\Models\LicenseApplication;
use App\Models\User;
use App\Modules\AIAgent\Enums\AgentIntent;
use App\Modules\AIAgent\Support\AgentTranslator;
use App\Modules\AIAgent\Support\ApplicationStatusLabelMapper;
use App\Modules\AIAgent\Support\LicenseTypeSlotExtractor;
use Illuminate\Support\Collection;

class AgentApplicationStatusHandler
{
    public function __construct(
        private readonly AgentApplicationNextStepService $nextStepService,
    ) {}

    /**
     * Build a deterministic payload when the citizen asks about application status.
     *
     * @return array<string, mixed>
     */
    public function buildPayload(User $citizen, string $language = 'ar'): array
    {
        $applications = $this->activeApplications($citizen);

        if ($applications->isEmpty()) {
            return $this->basePayload($language, [
                'reply' => AgentTranslator::message('ai_agent.no_active_applications', [], $language),
                'proposed_action' => null,
                'requires_confirmation' => false,
                'message_type' => 'no_eligible_application',
            ]);
        }

        if ($applications->count() === 1) {
            return $this->buildPayloadForApplication($citizen, $applications->first(), $language);
        }

        return $this->basePayload($language, [
            'reply' => AgentTranslator::message('ai_agent.multiple_active_applications', [
                'summary' => $this->formatApplicationList($applications, $language),
            ], $language),
            'proposed_action' => null,
            'missing_slots' => ['application_choice'],
            'requires_confirmation' => false,
        ]);
    }

    /**
     * Continue/status for an already selected application (pending workflow resume).
     *
     * @return array<string, mixed>
     */
    public function buildPayloadForApplication(User $citizen, LicenseApplication $application, string $language = 'ar'): array
    {
        if ((int) $application->citizen_id !== (int) $citizen->id) {
            return $this->basePayload($language, [
                'reply' => AgentTranslator::message('ai_agent.no_active_applications'),
                'proposed_action' => null,
                'requires_confirmation' => false,
            ]);
        }

        $statusReply = $this->nextStepService->statusReplyWithNextStep($application, $language);

        return $this->basePayload($language, [
            'reply' => $statusReply['reply'],
            'proposed_action' => [
                'name' => 'get_application_status',
                'arguments' => [
                    'application_id' => $application->id,
                ],
            ],
            'requires_confirmation' => false,
            'message_type' => 'application_status',
        ]);
    }

    /**
     * @return Collection<int, LicenseApplication>
     */
    private function activeApplications(User $citizen): Collection
    {
        return LicenseApplication::query()
            ->where('citizen_id', $citizen->id)
            ->whereIn('status', ApplicationStatus::activeValues())
            ->with(['licenseType', 'serviceType'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(string $language, array $overrides): array
    {
        return array_merge([
            'intent' => AgentIntent::GetApplicationStatus->value,
            'confidence' => 0.92,
            'language' => $language,
            'missing_slots' => [],
            'safety_status' => 'safe',
            'requires_human_support' => false,
            'execute_immediately' => true,
        ], $overrides);
    }

    /**
     * @param  Collection<int, LicenseApplication>  $applications
     */
    private function formatApplicationList(Collection $applications, string $language): string
    {
        return $applications
            ->map(function (LicenseApplication $application) use ($language): string {
                if ($language === 'en') {
                    $licenseLabel = LicenseTypeSlotExtractor::labelEn(
                        (string) ($application->licenseType?->code ?? '')
                    );
                    $statusLabel = ApplicationStatusLabelMapper::labelEn($application->status);

                    return '- '.$application->application_number.' ('.$licenseLabel.'): '.$statusLabel;
                }

                $licenseLabel = LicenseTypeSlotExtractor::labelAr(
                    (string) ($application->licenseType?->code ?? '')
                );
                $statusLabel = ApplicationStatusLabelMapper::labelAr($application->status);

                return '- '.$application->application_number.' — رخصة قيادة '.$licenseLabel.' — '.$statusLabel;
            })
            ->implode("\n");
    }
}
